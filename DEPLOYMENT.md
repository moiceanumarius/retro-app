# Retro App - Deployment Guide

## Overview
This guide covers deploying the Retro App in both development and production environments using Docker.

## Prerequisites
- Docker and Docker Compose installed
- Git
- Basic knowledge of Docker and Symfony

## Development Environment

### Quick Start
```bash
# Clone the repository
git clone <repository-url>
cd retro-app

# Set up development environment
./setup-dev.sh
```

### Manual Setup
```bash
# Start development containers
make dev

# Or using docker-compose directly
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Run migrations
make migrate

# Access the application
# Application: http://localhost:8080
# PHPMyAdmin: http://localhost:8081
# MailHog: http://localhost:8025
# Mercure: http://localhost:3000
```

### Development Features
- Xdebug enabled for debugging
- Hot reloading with volume mounts
- MailHog for email testing
- PHPMyAdmin for database management
- Development tools (vim, nano, htop)

## Production Environment

### Docker Production Setup

The production environment runs entirely in Docker containers, providing:
- **Isolation**: Each service runs in its own container
- **Scalability**: Easy to scale individual services
- **Consistency**: Same environment across different servers
- **Security**: Containerized services with minimal attack surface
- **Monitoring**: Built-in Prometheus monitoring
- **SSL/HTTPS**: Automatic SSL certificate support

### Production Services
- **Nginx**: Web server with SSL support and security headers
- **PHP-FPM**: Application server with production optimizations
- **MySQL**: Database with production configuration
- **Redis**: Caching and session storage
- **Mercure**: Real-time WebSocket updates
- **Prometheus**: Monitoring and metrics collection

### Prerequisites for Production
1. **Environment Configuration**
   ```bash
   # Copy and configure environment file
   cp .env.prod.example .env.prod
   # Edit .env.prod with your production values
   ```

2. **SSL Certificates** (Optional but recommended)
   ```bash
   # Create SSL directory
   mkdir -p docker/nginx/ssl
   
   # Add your SSL certificates
   # - docker/nginx/ssl/cert.pem
   # - docker/nginx/ssl/key.pem
   ```

3. **Domain Configuration**
   - Update `server_name` in `docker/nginx/nginx-prod.conf`
   - Configure DNS to point to your server

### Production Deployment

#### Automated Deployment
```bash
# Run the deployment script
./deploy-prod.sh
```

#### Manual Deployment
```bash
# Build production containers
make prod-build

# Start production environment
make prod

# Run migrations
docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

# Clear and warm up cache
docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec app php bin/console cache:clear --env=prod
docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec app php bin/console cache:warmup --env=prod
```

### Production Features
- **Security**: SSL/TLS encryption, security headers, rate limiting
- **Performance**: OPcache enabled, Redis caching, optimized MySQL
- **Monitoring**: Prometheus metrics collection
- **Scalability**: Multi-stage Docker builds, optimized images
- **Reliability**: Health checks, restart policies, persistent volumes

## Environment Variables

### Required for Production (.env.prod)
```bash
# Application
APP_ENV=prod
APP_DEBUG=false
APP_SECRET=your-super-secret-key-here

# Database
MYSQL_ROOT_PASSWORD=your-secure-root-password
MYSQL_USER=retro_user
MYSQL_PASSWORD=your-secure-user-password

# Redis
REDIS_PASSWORD=your-redis-password

# Mercure
MERCURE_URL=https://your-domain.com/.well-known/mercure
MERCURE_PUBLIC_URL=https://your-domain.com/.well-known/mercure
MERCURE_JWT_SECRET=your-mercure-jwt-secret

# Mailer
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

## Services

### Development Services
- **app**: PHP-FPM with Xdebug
- **nginx**: Web server (port 8080)
- **mysql**: Database server (port 3306)
- **phpmyadmin**: Database management (port 8081)
- **mailhog**: Email testing (ports 1025, 8025)
- **mercure**: Real-time updates (port 3000)

### Production Services
- **app**: PHP-FPM optimized for production
- **nginx**: Web server with SSL (ports 80, 443)
- **mysql**: Database server with production config
- **redis**: Caching server
- **mercure**: Real-time updates (port 3000)
- **prometheus**: Monitoring (port 9090)

## Monitoring

### Prometheus Metrics
- Application metrics: `http://localhost:9090`
- Available targets:
  - Prometheus itself
  - Retro App application
  - Nginx server
  - MySQL database
  - Redis cache

### Health Checks
```bash
# Check application health
curl -f http://localhost/

# Check container status
docker-compose ps

# View logs
make prod-logs
```

## Security Considerations

### Production Security Features
- SSL/TLS encryption
- Security headers (X-Frame-Options, X-XSS-Protection, etc.)
- Rate limiting on API endpoints
- Disabled dangerous Redis commands
- MySQL security hardening
- PHP security settings (expose_php=Off, etc.)

### Firewall Configuration
Open the following ports:
- **80**: HTTP (redirects to HTTPS)
- **443**: HTTPS
- **3000**: Mercure hub
- **9090**: Prometheus monitoring

## Troubleshooting

### Common Issues

1. **Port conflicts**
   ```bash
   # Check what's using the port
   lsof -i :8080
   # Stop conflicting services
   ```

2. **Permission issues**
   ```bash
   # Fix permissions
   docker-compose exec app chown -R www-data:www-data /var/www/html/var
   ```

3. **Database connection issues**
   ```bash
   # Check MySQL logs
   docker-compose logs mysql
   # Test connection
   docker-compose exec app php bin/console doctrine:database:create --if-not-exists
   ```

4. **Cache issues**
   ```bash
   # Clear all caches
   docker-compose exec app php bin/console cache:clear --env=prod
   docker-compose exec app php bin/console cache:warmup --env=prod
   ```

### Logs
```bash
# View all logs
make logs

# View specific service logs
docker-compose logs app
docker-compose logs nginx
docker-compose logs mysql
```

## Maintenance

### Updates
```bash
# Pull latest changes
git pull origin main

# Rebuild containers
make prod-build

# Restart services
make stop
make prod
```

### Backups
```bash
# Backup database
docker-compose exec mysql mysqldump -u root -p retro_app > backup.sql

# Backup volumes
docker run --rm -v retro_app_mysql_prod_data:/data -v $(pwd):/backup alpine tar czf /backup/mysql-backup.tar.gz -C /data .
```

## Support

For issues and questions:
1. Check the logs first
2. Review this deployment guide
3. Check Docker and Symfony documentation
4. Create an issue in the repository

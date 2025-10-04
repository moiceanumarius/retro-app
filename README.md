# Retro App - Agile Sprint Retrospective Application

A comprehensive web application for managing agile sprint retrospectives, built with Symfony 7.3 LTS and Docker. This application provides real-time collaboration features, voting systems, and comprehensive analytics for agile teams.

## 🚀 Features

### Core Functionality
- **Team Management**: Create and manage teams with role-based access control
- **Organization Management**: Multi-tenant organization support
- **Sprint Retrospectives**: Create, manage, and conduct retrospectives
- **Real-time Collaboration**: Live updates using Mercure WebSocket technology
- **Voting System**: Configurable voting limits for retrospective items
- **Action Items**: Track and manage action items with assignments and due dates
- **Timer Like System**: Interactive timer with user engagement features
- **Statistics & Analytics**: Comprehensive reporting and analytics dashboard

### Technical Features
- **Real-time Updates**: WebSocket-based live collaboration
- **Role-Based Access Control (RBAC)**: Granular permissions system
- **Responsive Design**: Mobile-friendly interface
- **Security**: CSRF protection, input validation, and secure authentication
- **Performance**: Redis caching, OPcache optimization, and database indexing
- **Monitoring**: Prometheus metrics collection and health checks

## 🛠️ Technology Stack

### Backend
- **Framework**: Symfony 7.3 LTS (PHP 8.3)
- **Database**: MySQL 8.0 with Doctrine ORM
- **Caching**: Redis for session and application caching
- **Real-time**: Mercure Hub for WebSocket communication
- **Authentication**: Symfony Security Component

### Frontend
- **Templating**: Twig templates
- **JavaScript**: Vanilla JavaScript (no React dependency)
- **CSS**: Custom CSS with responsive design
- **Real-time**: EventSource API for WebSocket communication

### Infrastructure
- **Containerization**: Docker & Docker Compose
- **Web Server**: Nginx with SSL/TLS support
- **Monitoring**: Prometheus metrics collection
- **Email**: MailHog for development, SMTP for production

## 📁 Project Structure

```
retro-app/
├── docker/                     # Docker configurations
│   ├── nginx/                 # Nginx configuration files
│   ├── php/                   # PHP Dockerfile and configurations
│   ├── mysql/                 # MySQL initialization scripts
│   ├── redis/                 # Redis configuration
│   └── prometheus/            # Monitoring configuration
├── src/                       # Symfony source code
│   ├── Controller/            # Application controllers
│   ├── Entity/               # Doctrine entities
│   ├── Form/                 # Symfony forms
│   ├── Repository/           # Data access layer
│   └── Security/             # Authentication and authorization
├── templates/                # Twig templates
├── public/                   # Public assets (CSS, JS, images)
├── config/                   # Symfony configuration
├── migrations/               # Database migrations
├── docker-compose.yml        # Base Docker Compose configuration
├── docker-compose.dev.yml    # Development environment
├── docker-compose.prod.yml   # Production environment
├── deploy-prod.sh            # Production deployment script
├── setup-dev.sh              # Development setup script
└── DEPLOYMENT.md             # Comprehensive deployment guide
```

## 🚀 Quick Start

### Prerequisites
- Docker and Docker Compose
- Git
- Make (optional, for convenience commands)

### Development Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/moiceanumarius/retro-app.git
   cd retro-app
   ```

2. **Configure development environment** (optional):
   ```bash
   # Edit docker-compose.dev.yml to customize MySQL credentials if needed
   ```

3. **Start development environment**:
   ```bash
   ./setup-dev.sh
   # or
   make dev
   ```

4. **Access the application**:
   - **Application**: http://localhost:8080
   - **PHPMyAdmin**: http://localhost:8081
   - **MailHog**: http://localhost:8025
   - **Mercure Hub**: http://localhost:3000

### Production Deployment

1. **Configure environment**:
   ```bash
   cp .env.prod.example .env.prod
   # Edit .env.prod with your production values including database credentials
   ```

2. **Deploy to production**:
   ```bash
   ./deploy-prod.sh
   # or
   make prod
   ```

3. **Access production**:
   - **Application**: https://your-domain.com
   - **Prometheus**: http://your-domain.com:9090
   - **Mercure Hub**: http://your-domain.com:3000

## 🔧 Available Commands

### Make Commands (Recommended)
```bash
# Development
make dev          # Start development environment
make dev-build    # Build development containers
make dev-logs     # View development logs
make dev-shell    # Open shell in development container

# Production
make prod         # Start production environment
make prod-build   # Build production containers
make prod-logs    # View production logs
make prod-shell   # Open shell in production container

# General
make stop         # Stop all containers
make clean        # Clean up containers and volumes
make logs         # View logs for all services
make shell        # Open shell in app container

# Symfony
make console      # Run Symfony console commands
make migrate      # Run database migrations
make cache-clear  # Clear Symfony cache
```

### Docker Compose Commands
```bash
# Development
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Production
docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Stop services
docker-compose down

# View logs
docker-compose logs -f
```

## 🗄️ Database Configuration

### Development
Configure your MySQL credentials in the Docker Compose files or environment variables.

### Production
Configure database credentials in `.env.prod` file with your secure credentials.

## 🔐 Security Features

### Production Security
- **SSL/TLS Encryption**: HTTPS with modern cipher suites
- **Security Headers**: X-Frame-Options, X-XSS-Protection, CSP, HSTS
- **Rate Limiting**: API endpoint protection
- **CSRF Protection**: Cross-site request forgery prevention
- **Input Validation**: Comprehensive data validation
- **SQL Injection Protection**: Doctrine ORM with parameterized queries

### Authentication & Authorization
- **User Authentication**: Secure login system
- **Role-Based Access Control**: Granular permissions
- **Session Management**: Secure session handling
- **Password Security**: Bcrypt hashing

## 📊 Monitoring & Analytics

### Prometheus Metrics
- Application performance metrics
- Database connection metrics
- Nginx server metrics
- Redis cache metrics
- Custom business metrics

### Application Analytics
- Retrospective participation rates
- Action item completion tracking
- Team performance metrics
- Voting patterns analysis

## 🌐 API Endpoints

### Retrospective Management
- `GET /retrospectives` - List retrospectives
- `POST /retrospectives` - Create retrospective
- `GET /retrospectives/{id}` - View retrospective
- `PUT /retrospectives/{id}` - Update retrospective
- `DELETE /retrospectives/{id}` - Delete retrospective

### Real-time Features
- `POST /retrospectives/{id}/timer-like` - Toggle timer like
- `GET /retrospectives/{id}/timer-like-status` - Get timer like status
- `POST /retrospectives/{id}/stop-timer` - Stop retrospective timer

### Team Management
- `GET /teams` - List teams
- `POST /teams` - Create team
- `GET /teams/{id}` - View team details
- `POST /teams/{id}/invite` - Invite team members

## 🔄 Real-time Features

### WebSocket Events
- **User Connection**: Live user presence
- **Timer Updates**: Real-time timer synchronization
- **Voting Updates**: Live voting results
- **Action Items**: Real-time action item updates
- **Timer Likes**: Interactive timer engagement

### Mercure Integration
- **Event Broadcasting**: Real-time event distribution
- **User Presence**: Live user tracking
- **Collaborative Features**: Multi-user real-time collaboration

## 📈 Performance Optimizations

### Production Optimizations
- **OPcache**: PHP bytecode caching
- **Redis Caching**: Session and application caching
- **Database Indexing**: Optimized query performance
- **Gzip Compression**: Reduced bandwidth usage
- **Static File Caching**: Optimized asset delivery
- **Multi-stage Docker Builds**: Smaller production images

### Development Features
- **Hot Reloading**: Volume mounts for instant updates
- **Xdebug**: Full debugging support
- **MailHog**: Email testing environment
- **PHPMyAdmin**: Database management interface

## 🧪 Testing

### Development Testing
- **MailHog**: Email testing at http://localhost:8025
- **PHPMyAdmin**: Database management at http://localhost:8081
- **Debug Mode**: Full Symfony debug toolbar

### Production Testing
- **Health Checks**: Automated service monitoring
- **SSL Testing**: HTTPS certificate validation
- **Performance Testing**: Load testing capabilities

## 📚 Documentation

- **[DEPLOYMENT.md](DEPLOYMENT.md)**: Comprehensive deployment guide
- **[API Documentation](docs/api.md)**: Complete API reference
- **[Security Guide](docs/security.md)**: Security best practices
- **[Development Guide](docs/development.md)**: Development setup and guidelines

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Guidelines
- Follow Symfony coding standards
- Write comprehensive tests
- Update documentation for new features
- Use conventional commit messages

## 📄 License

This project is licensed under the GPL-3.0 License - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

### Getting Help
- Check the [DEPLOYMENT.md](DEPLOYMENT.md) for deployment issues
- Review the [Issues](https://github.com/moiceanumarius/retro-app/issues) for known problems
- Create a new issue for bugs or feature requests

### Troubleshooting
- **Port Conflicts**: Check what's using the ports with `lsof -i :8080`
- **Permission Issues**: Run `docker-compose exec app chown -R www-data:www-data /var/www/html/var`
- **Database Issues**: Check MySQL logs with `docker-compose logs mysql` and verify your database credentials
- **Cache Issues**: Clear caches with `make cache-clear`

## 🎯 Roadmap

### Upcoming Features
- [ ] **Advanced Analytics**: More detailed reporting and insights
- [ ] **Integration APIs**: Third-party tool integrations
- [ ] **Mobile App**: Native mobile application
- [ ] **Advanced Permissions**: More granular access control
- [ ] **Export Features**: PDF and Excel export capabilities
- [ ] **Templates**: Pre-built retrospective templates
- [ ] **Notifications**: Email and in-app notifications
- [ ] **Multi-language Support**: Internationalization

### Performance Improvements
- [ ] **Database Optimization**: Query optimization and indexing
- [ ] **Caching Strategy**: Advanced caching implementation
- [ ] **CDN Integration**: Content delivery network support
- [ ] **Load Balancing**: Horizontal scaling capabilities

---

**Built with ❤️ for agile teams worldwide**
#!/bin/bash

# Production Deployment Script for Retro App
# This script helps deploy the application to production

set -e

echo "🚀 Starting Retro App Production Deployment..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if .env.prod exists
if [ ! -f .env.prod ]; then
    print_error ".env.prod file not found!"
    print_warning "Generating .env.prod template..."
    ./generate-env-prod.sh
    print_warning "Please edit .env.prod with your actual credentials before continuing."
    print_warning "Run this script again after configuring .env.prod"
    exit 1
fi

# Check if SSL certificates exist
if [ ! -f docker/nginx/ssl/cert.pem ] || [ ! -f docker/nginx/ssl/key.pem ]; then
    print_warning "SSL certificates not found in docker/nginx/ssl/"
    print_warning "Please add your SSL certificates:"
    print_warning "  - docker/nginx/ssl/cert.pem"
    print_warning "  - docker/nginx/ssl/key.pem"
    print_warning "Continuing without SSL..."
fi

# Detect docker-compose command
if command -v docker-compose >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker-compose"
elif command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker compose"
else
    print_error "docker-compose not found! Please install docker-compose."
    exit 1
fi

print_status "Using Docker Compose command: $DOCKER_COMPOSE"

# Load environment variables from .env.prod
print_status "Loading production environment variables from .env.prod..."
export $(cat .env.prod | grep -v '^#' | xargs)

# Stop existing containers
print_status "Stopping existing containers..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml down || true

# Build production images
print_status "Building production containers..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml build --no-cache

# Start production environment
print_status "Starting production environment..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml up -d

# Wait for services to be ready
print_status "Waiting for services to be ready..."
sleep 30

# Run database migrations
print_status "Running database migrations..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction

# Clear Symfony cache
print_status "Clearing Symfony cache..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml exec -T app php bin/console cache:clear --env=prod

# Warm up cache
print_status "Warming up cache..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml exec -T app php bin/console cache:warmup --env=prod

# Set proper permissions
print_status "Setting proper permissions..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml exec -T app chown -R www-data:www-data /var/www/html/var
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml exec -T app chmod -R 755 /var/www/html/var

# Health check
print_status "Performing health check..."
if curl -f http://localhost/ > /dev/null 2>&1; then
    print_status "✅ Application is running successfully!"
else
    print_error "❌ Application health check failed!"
    print_status "Checking logs..."
        $DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml logs --tail=50
    exit 1
fi

# Show running containers
print_status "Production containers status:"
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml ps

print_status "🎉 Production deployment completed successfully!"
print_status "Application is available at: http://localhost (or https://localhost with SSL)"
print_status "Prometheus monitoring: http://localhost:9090"
print_status "Mercure hub: http://localhost:3000"
print_status ""
print_status "🐳 Docker Production Environment:"
print_status "   - All services running in Docker containers"
print_status "   - MySQL, Redis, Nginx, PHP-FPM, Mercure, Prometheus"
print_status "   - Production-optimized configurations"
print_status "   - Automatic restarts and health monitoring"

echo ""
print_warning "Don't forget to:"
print_warning "1. Update your domain name in docker/nginx/nginx-prod.conf"
print_warning "2. Configure your DNS to point to this server"
print_warning "3. Set up SSL certificates for HTTPS"
print_warning "4. Configure your firewall to allow ports 80, 443, 3000, 9090"

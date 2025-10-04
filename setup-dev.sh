#!/bin/bash

# Development Environment Setup Script for Retro App
# This script helps set up the development environment

set -e

echo "🛠️  Setting up Retro App Development Environment..."

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

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    print_error "Docker is not running. Please start Docker and try again."
    exit 1
fi

# Stop existing containers
print_status "Stopping existing containers..."
docker-compose -f docker-compose.yml -f docker-compose.dev.yml down || true

# Build development images
print_status "Building development containers..."
docker-compose -f docker-compose.yml -f docker-compose.dev.yml build

# Start development environment
print_status "Starting development environment..."
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Wait for services to be ready
print_status "Waiting for services to be ready..."
sleep 20

# Run database migrations
print_status "Running database migrations..."
docker-compose -f docker-compose.yml -f docker-compose.dev.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction

# Load fixtures (optional)
read -p "Do you want to load test fixtures? (y/N): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    print_status "Loading test fixtures..."
    docker-compose -f docker-compose.yml -f docker-compose.dev.yml exec -T app php bin/console doctrine:fixtures:load --no-interaction
fi

# Clear Symfony cache
print_status "Clearing Symfony cache..."
docker-compose -f docker-compose.yml -f docker-compose.dev.yml exec -T app php bin/console cache:clear

# Set proper permissions
print_status "Setting proper permissions..."
docker-compose -f docker-compose.yml -f docker-compose.dev.yml exec -T app chown -R www-data:www-data /var/www/html/var
docker-compose -f docker-compose.yml -f docker-compose.dev.yml exec -T app chmod -R 755 /var/www/html/var

# Health check
print_status "Performing health check..."
if curl -f http://localhost:8080/ > /dev/null 2>&1; then
    print_status "✅ Development environment is running successfully!"
else
    print_error "❌ Development environment health check failed!"
    print_status "Checking logs..."
    docker-compose -f docker-compose.yml -f docker-compose.dev.yml logs --tail=50
    exit 1
fi

# Show running containers
print_status "Development containers status:"
docker-compose -f docker-compose.yml -f docker-compose.dev.yml ps

print_status "🎉 Development environment setup completed!"
print_status "Application is available at: http://localhost:8080"
print_status "PHPMyAdmin: http://localhost:8081"
print_status "MailHog: http://localhost:8025"
print_status "Mercure hub: http://localhost:3000"

echo ""
print_status "Useful commands:"
print_status "  make dev-logs    - View logs"
print_status "  make dev-shell   - Open shell in container"
print_status "  make stop        - Stop all containers"

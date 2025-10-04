#!/bin/bash

# MySQL Production Reset Script for Retro App
# This script stops, removes, and restarts the MySQL production container and its volume.
# USE WITH CAUTION: This will DELETE ALL DATA in your MySQL production database.

set -e

echo "⚠️  Starting MySQL Production Reset..."
echo "    This will DELETE ALL DATA in your MySQL production database."
read -p "    Are you sure you want to continue? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "MySQL reset cancelled."
    exit 0
fi

echo "🔄 Resetting MySQL Production Database..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

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

# Load environment variables
print_status "Loading production environment variables..."
if [ -f .env.prod ]; then
    export $(cat .env.prod | grep -v '^#' | xargs)
    print_status "Environment variables loaded from .env.prod"
else
    print_error ".env.prod file not found! Cannot proceed without production environment variables."
    exit 1
fi

# Verify we're in production environment
if [ "$APP_ENV" != "prod" ]; then
    print_error "This script should only be run in production environment!"
    print_error "Current APP_ENV: $APP_ENV"
    exit 1
fi

print_status "✅ Production environment confirmed (APP_ENV: $APP_ENV)"

# Verify MySQL credentials are set
if [ -z "$MYSQL_ROOT_PASSWORD" ] || [ -z "$MYSQL_USER" ] || [ -z "$MYSQL_PASSWORD" ]; then
    print_error "MySQL credentials not properly configured!"
    print_error "Please check your .env.prod file and ensure these variables are set:"
    print_error "  - MYSQL_ROOT_PASSWORD"
    print_error "  - MYSQL_USER" 
    print_error "  - MYSQL_PASSWORD"
    exit 1
fi

print_status "✅ MySQL credentials verified"

# Stop MySQL container
print_status "Stopping MySQL container..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml stop mysql || true

# Remove MySQL container
print_status "Removing MySQL container..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml rm -f mysql || true

# Remove MySQL volumes
print_status "Removing MySQL volumes..."
docker volume rm retro_app_mysql_data_prod || true
docker volume rm retro_app_mysql_logs_prod || true

# Recreate MySQL container
print_status "Recreating MySQL container..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml up -d mysql

# Wait for MySQL to be ready
print_status "Waiting for MySQL to be ready..."
sleep 30

# Check MySQL status
print_status "Checking MySQL status..."
$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml logs mysql

print_status "✅ MySQL reset completed!"
print_warning "Remember to run database migrations after this to recreate your schema:"
print_warning "  $DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction"

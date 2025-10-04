#!/bin/bash

# Reset MySQL Production Database
# This script removes MySQL volumes and recreates them for a fresh start

set -e

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
print_warning "You may need to run database migrations after this reset."

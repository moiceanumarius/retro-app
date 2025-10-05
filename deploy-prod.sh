#!/bin/bash

# Production deployment script for Retro App
# This script loads environment variables and deploys the application

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if .env.prod exists
if [ ! -f ".env.prod" ]; then
    print_error ".env.prod file not found!"
    print_status "Please create .env.prod file with your production environment variables"
    exit 1
fi

# Load environment variables from .env.prod
print_status "Loading production environment variables from .env.prod..."
export $(cat .env.prod | grep -v '^#' | xargs)

# Verify that required variables are set
if [ -z "$MYSQL_USER" ] || [ -z "$MYSQL_PASSWORD" ] || [ -z "$MYSQL_ROOT_PASSWORD" ]; then
    print_error "Required MySQL environment variables are not set!"
    print_status "Please check your .env.prod file"
    exit 1
fi

print_success "Environment variables loaded successfully"

# Stop existing containers
print_status "Stopping existing containers..."
sudo docker compose --env-file .env.prod -f docker-compose.prod.yml down

# Pull latest images (if any)
print_status "Pulling latest images..."
sudo docker compose --env-file .env.prod -f docker-compose.prod.yml pull

# Build and start containers
print_status "Building and starting containers..."
sudo docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build

# Wait for services to be ready
print_status "Waiting for services to be ready..."
sleep 10

# Check container status
print_status "Checking container status..."
sudo docker compose --env-file .env.prod -f docker-compose.prod.yml ps

print_success "Deployment completed successfully!"
print_warning "Don't forget to:"
print_warning "1. Configure your domain DNS to point to this server"
print_warning "2. Set up SSL certificates for HTTPS"
print_warning "3. Configure your firewall to allow ports 80, 443, 3000, 9090"
print_warning "4. Configure your firewall to allow port 3306 for MySQL access"

print_status "Application should be accessible at:"
print_status "- HTTP: http://your-domain.com"
print_status "- MySQL: your-domain.com:3306"
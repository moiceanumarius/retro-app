#!/bin/bash

# Setup Production Data Script
# This script sets up initial data in production database

# --- Configuration ---
DOCKER_COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"

# --- Helper Functions ---
print_status() {
  echo -e "\n\e[1;34mSTATUS:\e[0m $1"
}

print_success() {
  echo -e "\n\e[1;32mSUCCESS:\e[0m $1"
}

print_error() {
  echo -e "\n\e[1;31mERROR:\e[0m $1" >&2
}

print_warning() {
  echo -e "\n\e[1;33mWARNING:\e[0m $1"
}

# --- Main Setup Logic ---

print_status "Setting up production data..."

# 1. Check if containers are running
print_status "Checking if containers are running..."
if ! sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" ps | grep -q "Up"; then
  print_error "Containers are not running. Please start them first with ./deploy-prod.sh"
  exit 1
fi

# 2. Run database migrations
print_status "Running database migrations..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console doctrine:migrations:migrate --no-interaction; then
  print_success "Database migrations completed successfully."
else
  print_error "Database migrations failed. Check logs for details."
  exit 1
fi

# 3. Load RBAC fixtures (roles and permissions)
print_status "Loading RBAC fixtures (roles and permissions)..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console doctrine:fixtures:load --fixtures=src/DataFixtures/RbacFixtures.php --no-interaction; then
  print_success "RBAC fixtures loaded successfully."
else
  print_error "Failed to load RBAC fixtures. Check logs for details."
  exit 1
fi

# 4. Load user fixtures (test users)
print_status "Loading user fixtures (test users)..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console doctrine:fixtures:load --fixtures=src/DataFixtures/UserFixtures.php --no-interaction; then
  print_success "User fixtures loaded successfully."
else
  print_error "Failed to load user fixtures. Check logs for details."
  exit 1
fi

# 5. Clear cache
print_status "Clearing application cache..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console cache:clear --env=prod; then
  print_success "Cache cleared successfully."
else
  print_warning "Cache clear failed, but continuing..."
fi

print_success "Production data setup completed successfully!"
print_warning "Default users created:"
print_warning "  - Admin: admin@retroapp.com (password: password123)"
print_warning "  - Facilitator: facilitator@retroapp.com (password: password123)"
print_warning "  - Member: member@retroapp.com (password: password123)"
print_warning ""
print_warning "IMPORTANT: Change these passwords immediately after first login!"
print_warning ""
print_warning "Roles created:"
print_warning "  - ROLE_ADMIN: Full system access"
print_warning "  - ROLE_FACILITATOR: Can facilitate retrospectives and manage teams"
print_warning "  - ROLE_SUPERVISOR: Can manage teams and facilitate retrospectives"
print_warning "  - ROLE_MEMBER: Can participate in retrospectives"

#!/bin/bash

# --- Configuration ---
DOCKER_COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"
PROJECT_DIR="/var/www/retro-app" # Adjust if your project path is different on the server

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

# --- Main Logic ---

# 1. Navigate to project directory
print_status "Navigating to project directory: $PROJECT_DIR"
cd "$PROJECT_DIR" || { print_error "Failed to change directory to $PROJECT_DIR. Exiting."; exit 1; }

# 2. Check if PHP container is running
print_status "Checking if PHP container is running..."
if ! sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" ps | grep "retro_app_php_prod" | grep "Up"; then
  print_error "PHP container (retro_app_php_prod) is not running. Please run ./deploy-prod.sh first. Exiting."
  exit 1
fi
print_success "PHP container is running."

# 3. Run database migrations (ensure schema is up to date)
print_status "Running database migrations..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console doctrine:migrations:migrate --no-interaction; then
  print_success "Database migrations completed successfully."
else
  print_error "Failed to run database migrations. Check logs for details. Exiting."
  exit 1
fi

# 4. Load all fixtures (roles, permissions, and test users)
print_status "Loading all fixtures (roles, permissions, and test users)..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console doctrine:fixtures:load --no-interaction; then
  print_success "All fixtures loaded successfully."
else
  print_error "Failed to load fixtures. Check logs for details. Exiting."
  exit 1
fi

# 5. Clear cache
print_status "Clearing application cache..."
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console cache:clear; then
  print_success "Application cache cleared."
else
  print_warning "Failed to clear application cache. Continuing..."
fi

print_success "Production data setup completed successfully!"
print_status "Test users available:"
echo "- admin@retroapp.com (password: password123) - Administrator"
echo "- facilitator@retroapp.com (password: password123) - Facilitator" 
echo "- member@retroapp.com (password: password123) - Member"

#!/bin/bash

# Create Admin User Script
# This script creates a custom admin user in production

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

# --- Main Logic ---

print_status "Creating custom admin user..."

# Check if containers are running
if ! sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" ps | grep -q "Up"; then
  print_error "Containers are not running. Please start them first with ./deploy-prod.sh"
  exit 1
fi

# Get user input
echo -e "\n\e[1;36mEnter admin user details:\e[0m"
read -p "Email: " email
read -p "First Name: " first_name
read -p "Last Name: " last_name
read -s -p "Password: " password
echo ""

if [[ -z "$email" || -z "$first_name" || -z "$last_name" || -z "$password" ]]; then
  print_error "All fields are required!"
  exit 1
fi

# Create user using Symfony command
print_status "Creating user: $email"
if sudo docker compose --env-file "$ENV_FILE" -f "$DOCKER_COMPOSE_FILE" exec -T app php bin/console app:create-user \
  "$email" \
  "$password" \
  "$first_name" \
  "$last_name" \
  --role="ROLE_ADMIN" \
  --verified; then
  
  print_success "Admin user '$email' created successfully!"
else
  print_error "Failed to create admin user. Check logs for details."
  exit 1
fi

print_warning "Admin user created with ROLE_ADMIN permissions."
print_warning "You can now log in to the application with these credentials."

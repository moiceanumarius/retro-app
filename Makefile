# Retro App Makefile
# Commands for managing development and production environments

.PHONY: help dev prod stop clean logs shell

# Default target
help:
	@echo "Retro App - Available commands:"
	@echo ""
	@echo "Development:"
	@echo "  make dev          - Start development environment"
	@echo "  make dev-build    - Build development containers"
	@echo "  make dev-logs     - Show development logs"
	@echo "  make dev-shell    - Open shell in development container"
	@echo ""
	@echo "Production:"
	@echo "  make prod         - Start production environment"
	@echo "  make prod-build   - Build production containers"
	@echo "  make prod-logs    - Show production logs"
	@echo "  make prod-shell   - Open shell in production container"
	@echo ""
	@echo "General:"
	@echo "  make stop         - Stop all containers"
	@echo "  make clean        - Clean up containers and volumes"
	@echo "  make logs         - Show logs for all services"
	@echo "  make shell        - Open shell in app container"

# Development commands
dev:
	@echo "Starting development environment..."
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

dev-build:
	@echo "Building development containers..."
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml build

dev-logs:
	@echo "Showing development logs..."
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml logs -f

dev-shell:
	@echo "Opening shell in development container..."
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml exec app bash

# Production commands
prod:
	@echo "Starting production environment..."
	@if [ ! -f .env.prod ]; then \
		echo "Error: .env.prod file not found. Please copy .env.prod.example to .env.prod and configure it."; \
		exit 1; \
	fi
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d

prod-build:
	@echo "Building production containers..."
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml build

prod-logs:
	@echo "Showing production logs..."
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml logs -f

prod-shell:
	@echo "Opening shell in production container..."
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec app bash

# General commands
stop:
	@echo "Stopping all containers..."
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml down

clean:
	@echo "Cleaning up containers and volumes..."
	docker-compose -f docker-compose.yml -f docker-compose.dev.yml down -v --remove-orphans
	docker-compose -f docker-compose.yml -f docker-compose.prod.yml down -v --remove-orphans
	docker system prune -f

logs:
	@echo "Showing logs for all services..."
	docker-compose logs -f

shell:
	@echo "Opening shell in app container..."
	docker-compose exec app bash

# Symfony commands
console:
	@echo "Running Symfony console command..."
	docker-compose exec app php bin/console $(filter-out $@,$(MAKECMDGOALS))

cache-clear:
	@echo "Clearing Symfony cache..."
	docker-compose exec app php bin/console cache:clear

migrate:
	@echo "Running database migrations..."
	docker-compose exec app php bin/console doctrine:migrations:migrate

# Allow passing arguments to console command
%:
	@:

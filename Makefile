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
	@echo ""
	@echo "Environment:"
	@echo "  make env-dev      - Generate .env.dev file"
	@echo "  make env-prod     - Generate .env.prod file"

# Development commands
dev:
	@echo "Starting development environment..."
	@./setup-dev.sh

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
	@./deploy-prod.sh

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

# Database backup commands
backup-db-dev:
	@echo "Creating development database backup..."
	@mkdir -p backups
	@docker exec retro_app_mysql_dev mysqldump -uroot -proot --single-transaction --quick --lock-tables=false retro_app > backups/retro_app_dev_$(shell date +%Y%m%d_%H%M%S).sql
	@echo "✅ Backup created successfully!"
	@ls -lh backups/ | tail -1

backup-db-prod:
	@echo "Creating production database backup..."
	@mkdir -p backups
	@docker exec retro_app_mariadb_prod mysqldump -uroot -p$$MYSQL_ROOT_PASSWORD --single-transaction --quick --lock-tables=false retro_app > backups/retro_app_prod_$(shell date +%Y%m%d_%H%M%S).sql
	@echo "✅ Backup created successfully!"
	@ls -lh backups/ | tail -1

restore-db-dev:
	@echo "📂 Available backups:"
	@ls -1 backups/*dev*.sql 2>/dev/null || echo "No dev backups found"
	@read -p "Enter backup filename (from backups/ directory): " backup; \
	if [ -f "backups/$$backup" ]; then \
		echo "Restoring $$backup..."; \
		docker exec -i retro_app_mysql_dev mysql -uroot -proot retro_app < backups/$$backup; \
		echo "✅ Database restored successfully!"; \
	else \
		echo "❌ Backup file not found!"; \
		exit 1; \
	fi

list-backups:
	@echo "📂 Available database backups:"
	@ls -lh backups/*.sql 2>/dev/null || echo "No backups found. Run 'make backup-db-dev' to create one."

clean-old-backups:
	@echo "Cleaning backups older than 7 days..."
	@find backups/ -name "*.sql" -mtime +7 -delete 2>/dev/null || true
	@echo "✅ Old backups cleaned!"

mysql-status:
	@echo "📊 MySQL Status:"
	@docker exec retro_app_mysql_dev mysql -uroot -proot -e "SHOW PROCESSLIST;"
	@echo ""
	@echo "📁 Databases:"
	@docker exec retro_app_mysql_dev mysql -uroot -proot -e "SHOW DATABASES;"

# Environment generation commands
env-dev:
	@echo "Generating .env.dev file..."
	@./generate-env-dev.sh

env-prod:
	@echo "Generating .env.prod file..."
	@./generate-env-prod.sh

# Allow passing arguments to console command
%:
	@:

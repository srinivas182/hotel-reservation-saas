# ---------------------------------------------------------------------
# Developer shortcuts. Run `make help` for the list.
# ---------------------------------------------------------------------
DC  := docker compose
APP := $(DC) exec app

.DEFAULT_GOAL := help
.PHONY: help up down build shell install fresh test check fix logs

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Start the stack (app, nginx, mysql, redis, horizon, scheduler, mailpit)
	$(DC) up -d

down: ## Stop the stack
	$(DC) down

build: ## Rebuild the PHP image
	$(DC) build app

shell: ## Open a shell in the app container
	$(APP) sh

install: ## First-time setup: dependencies, key, database, assets
	$(APP) composer install
	$(APP) sh -c '[ -f .env ] || cp .env.example .env'
	$(APP) php artisan key:generate
	$(APP) php artisan migrate --seed
	$(DC) run --rm vite sh -c "npm ci && npm run build"

fresh: ## Rebuild the database with seed data
	$(APP) php artisan migrate:fresh --seed

test: ## Run the full test suite in parallel
	$(APP) php artisan test --parallel

check: ## Pre-push gate: style, static analysis, types, tests
	$(APP) vendor/bin/pint --test
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G
	$(DC) run --rm vite sh -c "npm run check && npm run types:check"
	$(APP) php artisan test --parallel

fix: ## Auto-fix PHP and frontend formatting
	$(APP) vendor/bin/pint
	$(DC) run --rm vite npm run check:fix

logs: ## Tail application logs
	$(APP) php artisan pail

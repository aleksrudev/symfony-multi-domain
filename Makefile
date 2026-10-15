.PHONY: help start start-all stop restart build console composer migrate dev-db-recreate diff cc worker outbox-worker scheduler architecture test-unit test-db-prepare phpstan cs-fix cs-check test lint logs \
        prod-build prod-start prod-start-all prod-stop prod-restart prod-logs prod-migrate prod-cc
.DEFAULT_GOAL := help

DC = docker compose
PHP = $(DC) exec php
CONSOLE = $(PHP) bin/console

DC_PROD = docker compose -f compose.yaml -f compose.prod.yaml
PHP_PROD = $(DC_PROD) exec php
CONSOLE_PROD = $(PHP_PROD) bin/console

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	$(DC) build

start: ## Start php + database
	$(DC) up -d

start-all: ## Start all services including workers
	$(DC) --profile worker up -d

stop: ## Stop all services
	$(DC) down

restart: ## Restart all services
	$(DC) down && $(DC) up -d

logs: ## Tail all service logs
	$(DC) logs -f

console: ## Run Symfony console (usage: make console CMD="cache:clear")
	$(CONSOLE) $(CMD)

composer: ## Run Composer (usage: make composer CMD="require foo/bar")
	$(PHP) composer $(CMD)

migrate: ## Run database migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

dev-db-recreate: ## Recreate dev database and run migrations (deletes all dev data)
	$(CONSOLE) doctrine:database:drop --env=dev --force --if-exists --no-interaction
	$(CONSOLE) doctrine:database:create --env=dev --no-interaction
	$(CONSOLE) doctrine:migrations:migrate --env=dev --no-interaction --allow-no-migration

diff: ## Generate a migration diff
	$(CONSOLE) doctrine:migrations:diff

cc: ## Clear cache
	$(CONSOLE) cache:clear

worker: ## Consume async transport
	$(CONSOLE) messenger:consume async -vv

outbox-worker: ## Consume the transactional outbox transport
	$(CONSOLE) messenger:consume outbox -vv

scheduler: ## Consume scheduler transport
	$(CONSOLE) messenger:consume scheduler_default -vv

architecture: ## Check module and layer dependencies
	$(PHP) bin/architecture

test-unit: ## Run isolated tests without a database
	$(PHP) vendor/bin/phpunit --testsuite Unit

test-db-prepare: ## Prepare a dedicated test database (explicit opt-in)
	$(PHP) bin/test-db-prepare --confirm-test-database

phpstan: ## Run PHPStan
	$(PHP) vendor/bin/phpstan analyse --memory-limit=256M

cs-fix: ## Run PHP-CS-Fixer (fix)
	$(PHP) vendor/bin/php-cs-fixer fix

cs-check: ## Run PHP-CS-Fixer (dry-run)
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

test: ## Run PHPUnit tests
	$(PHP) vendor/bin/phpunit

lint: ## Run all linters (PHPStan + CS check)
	@$(MAKE) phpstan
	@$(MAKE) cs-check

prod-build: ## [PROD] Build Docker images
	$(DC_PROD) build

prod-start: ## [PROD] Start php + database
	$(DC_PROD) up -d

prod-start-all: ## [PROD] Start all services including workers
	$(DC_PROD) --profile worker up -d

prod-stop: ## [PROD] Stop all services
	$(DC_PROD) down

prod-restart: ## [PROD] Restart all services
	$(DC_PROD) down && $(DC_PROD) up -d

prod-logs: ## [PROD] Tail all service logs
	$(DC_PROD) logs -f

prod-migrate: ## [PROD] Run database migrations
	$(CONSOLE_PROD) doctrine:migrations:migrate --no-interaction

prod-cc: ## [PROD] Clear cache
	$(CONSOLE_PROD) cache:clear

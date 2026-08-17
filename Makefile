DC = docker compose
PHP = $(DC) run --rm php

.PHONY: build install update test test-cached coverage types stan lint lint-fix rector rector-fix ci shell test-dbs

build: ## Build the dev image
	$(DC) build php

install: ## composer install
	$(PHP) composer install

update: ## composer update
	$(PHP) composer update

test: ## Run the test suite
	$(PHP) vendor/bin/pest --ci

test-cached: ## Full suite again through the cached resolver (parity matrix)
	$(DC) run --rm -e BOUNCER_TEST_RESOLVER=cached php vendor/bin/pest --ci

coverage: ## Tests + 100% coverage gate
	$(PHP) php -d pcov.directory=/app -d 'pcov.exclude=~/(vendor|tests|\.cache)/~' vendor/bin/pest --coverage --min=100

types: ## 100% type coverage gate
	$(PHP) php -d memory_limit=1G vendor/bin/pest --type-coverage --min=100

stan: ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Pint check (no changes)
	$(PHP) vendor/bin/pint --test

lint-fix: ## Pint apply
	$(PHP) vendor/bin/pint

rector: ## Rector dry-run
	$(PHP) vendor/bin/rector process --dry-run

rector-fix: ## Rector apply
	$(PHP) vendor/bin/rector process

ci: lint stan rector coverage types test-cached ## Everything CI runs

shell: ## Shell inside the container
	$(PHP) sh

test-dbs: ## Suite against MySQL & Postgres (waits for healthchecks)
	$(DC) up -d --wait mysql postgres
	$(PHP) sh -c "DB_CONNECTION=mysql DB_HOST=mysql DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=secret DB_DATABASE=bouncer vendor/bin/pest --ci"
	$(PHP) sh -c "DB_CONNECTION=pgsql DB_HOST=postgres DB_PORT=5432 DB_USERNAME=postgres DB_PASSWORD=secret DB_DATABASE=bouncer vendor/bin/pest --ci"

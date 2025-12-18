.DEFAULT_GOAL := help
DOCKER_CMD := $(shell command -v podman 2> /dev/null || command -v docker 2> /dev/null)
DOCKER_COMPOSE_CMD := $(shell command -v podman-compose 2> /dev/null || command -v docker-compose 2> /dev/null)
DOCKER_COMPOSE_FILE := docker-compose.yml
SHELL := /usr/bin/env bash
MAKEFLAGS += --no-builtin-rules
MAKEFLAGS += --no-builtin-variables

# General

.PHONY: help init install clean build lint format test analyse check-all

help: ## list available commands
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

init: ## verify that all the required commands are already installed
	@if [ -z "$$CI" ]; then \
		function cmd { \
			if ! command -v "$$1" &>/dev/null ; then \
				echo "error: missing required command in PATH: $$1" >&2 ;\
				return 1 ;\
			fi \
		} ;\
		cmd $(DOCKER_CMD) ; \
		cmd actionlint ;\
		cmd composer;\
		cp .githooks/* .git/hooks/ ;\
	fi

install: init ## install local project dependencies
	composer install

clean: init ## cleans up all containers and other temporary files
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) down

build: init ## build files for distribution (use PLUGIN_VERSION env var to override version)
	@echo "Building distribution package..."
	@# Get version from PLUGIN_VERSION env var, or fall back to git tag, or "dev-main"
	$(eval VERSION := $(or $(PLUGIN_VERSION),$(shell git describe --tags --abbrev=0 2>/dev/null),dev-main))
	@echo "Version: $(VERSION)"
	@# Create build directory
	mkdir -p dist/KarlaDelivery/src
	@# Copy source files
	cp -r src dist/KarlaDelivery/
	@# Copy composer.json and inject version
	@if [ "$(VERSION)" != "dev-main" ]; then \
		jq '.version = "$(VERSION:v%=%)"' composer.json > dist/KarlaDelivery/composer.json; \
	else \
		cp composer.json dist/KarlaDelivery/composer.json; \
	fi
	@# Create ZIP
	cd dist && zip -r KarlaDelivery.zip KarlaDelivery
	@echo "✓ Build complete: dist/KarlaDelivery.zip (version $(VERSION))"

lint: init ## check code style with PHP-CS-Fixer
	vendor/bin/php-cs-fixer fix --dry-run --diff

format: init ## automatically format code with PHP-CS-Fixer
	vendor/bin/php-cs-fixer fix

analyse: init ## run static analysis with PHPStan
	php -d memory_limit=512M vendor/bin/phpstan analyse

check-all: lint analyse test coverage ## run all quality checks (lint, analyse, test, coverage)

ifdef CI
# run tests in a CI environment
test:
	vendor/bin/phpunit --no-logging --no-coverage
else
test: init ## run tests in the local environment
	vendor/bin/phpunit --no-coverage
endif

coverage: init ## generate code coverage report and enforce minimum thresholds
	XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html coverage/html --coverage-clover coverage.xml --coverage-text
	@echo "Coverage report generated in coverage/html/index.html"
	@php scripts/check-coverage.php


# Dockware

.PHONY: dockware-attach dockware-destroy dockware-start

dockware-attach: init ## attach to the shopware shop container
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) exec dockware bash

dockware-destroy: init ## destroy the shopware shop in a container
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) down dockware

dockware-start: init ## run the shopware shop in a container
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) up -d dockware
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) exec -d dockware bash -c 'sudo chown www-data:www-data -R /var/www'

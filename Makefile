.DEFAULT_GOAL := help
DOCKER_CMD := $(shell command -v podman 2> /dev/null || command -v docker 2> /dev/null)
DOCKER_COMPOSE_CMD := $(shell command -v podman-compose 2> /dev/null || command -v docker-compose 2> /dev/null)
DOCKER_COMPOSE_FILE := docker-compose.yml
SHELL := /usr/bin/env bash
MAKEFLAGS += --no-builtin-rules
MAKEFLAGS += --no-builtin-variables

# General

.PHONY: help init install clean build lint format test

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

build: init ## build files for distribution
	mkdir -p dist/KarlaDelivery/src
	cp -r src dist/KarlaDelivery/
	cp composer.json dist/KarlaDelivery/composer.json
	zip -r dist/KarlaDelivery.zip dist/KarlaDelivery

lint: init ## lint syntax code
	vendor/bin/phpcs src/

format: init ## automatically format code
	vendor/bin/phpcbf src/

ifdef CI
# run tests with coverage in a CI environment
test:
	vendor/bin/phpunit --no-logging tests
else
test: init ## run tests with coverage in the local environment
	vendor/bin/phpunit tests
endif


# Dockware

.PHONY: dockware-attach dockware-destroy dockware-start

dockware-attach: init ## attach to the shopware shop container
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) exec dockware bash

dockware-destroy: init ## destroy the shopware shop in a container
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) down dockware

dockware-start: init ## run the shopware shop in a container
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) up -d dockware
	$(DOCKER_COMPOSE_CMD) -f $(DOCKER_COMPOSE_FILE) exec -d dockware bash -c 'sudo chown www-data:www-data -R /var/www'

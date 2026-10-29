# The make targets a WordPress plugin shares, from pryley/wp-plugin-tooling.
#
# A plugin's Makefile sets its variables, defines docker-check and test:install,
# then includes this file. Those two stay in the plugin because this file only
# exists once test:install has run Composer:
#
#     PLUGIN := my-plugin
#     TOOLING_MK := vendor/pryley/wp-plugin-tooling/make/plugin.mk
#     ifneq ($(wildcard $(TOOLING_MK)),)
#     include $(TOOLING_MK)
#     else
#     .DEFAULT:
#     	@printf '\nRun `make test:install` first.\n\n'
#     endif
#
# The plugin's other targets follow the include. A shared target is extended
# through a hook: a variable the plugin sets to the commands to run.
#
# Every target here runs on GNU Make 3.81, the version macOS ships.

TOOLING ?= vendor/pryley/wp-plugin-tooling
TOOLS = $(TOOLING)/tools

COMPOSER ?= COMPOSER_PROCESS_TIMEOUT=0 composer
PLUGIN ?= $(notdir $(CURDIR))
VERSION ?= $(shell perl -lne 'm{Stable tag: .*?(.+)} and print $$1' readme.txt)
WP_ENV_PORT ?= 8888
WPENV_BIN ?= $(shell test -x node_modules/.bin/wp-env && printf 'node_modules/.bin/wp-env' || printf 'npx @wordpress/env')
WPENV ?= $(WPENV_BIN) run cli --env-cwd=wp-content/plugins/$(PLUGIN)
WPENV_START ?= WP_ENV_PORT=$(WP_ENV_PORT) $(WPENV_BIN) start
WPENV_CONFIGS ?= .wp-env.json $(wildcard $(MULTISITE_DIR)/.wp-env.json)

TEST_DIR ?= tests
PEST ?= vendor/bin/pest --test-directory=$(TEST_DIR) --colors=always
SUITES ?= Unit Integration ThirdParty Import
RANDOM_SUITES ?= $(SUITES)

MULTISITE_DIR ?= tests/multisite
MULTISITE_PORT ?= 8889
MULTISITE_TITLE ?= $(PLUGIN) Network

COVERAGE_CLOVERS ?= tests/coverage/clover.xml tests/coverage/multisite.xml
COVERAGE_SOURCE ?= plugin

I18N_DOMAIN ?= $(PLUGIN)
I18N_EXCLUDE ?= node_modules,tests,vendor
I18N_HEADERS ?= {}

# The hooks. Each is empty unless the plugin sets it.
#   TEST_JS             runs before the Pest suites in `make test`
#   TEST_ALL_EXTRA      more suite targets for `make test:all`
#   COVERAGE_ALL_EXTRA  more coverage targets for `make coverage:all`, run before the merge
#   ENV_UPDATE_AFTER    runs after `make env:update` has updated the instances
#   I18N_AFTER          runs after `make build:i18n` has built the .mo files
#   MULTISITE_PREPARE   runs before the multisite instance starts
#   MULTISITE_SETUP     runs in the multisite folder once the network is configured; the
#                       `quietly` shell function prints a command's output only when it fails
TEST_JS ?=
TEST_ALL_EXTRA ?=
COVERAGE_ALL_EXTRA ?=
ENV_UPDATE_AFTER ?=
I18N_AFTER ?=
MULTISITE_PREPARE ?=
MULTISITE_SETUP ?=

.PHONY: analyse
analyse: env-check ## Run the phpstan analyser (inside wp-env)
	$(WPENV) env XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit 2G

.PHONY: build\:i18n
build\:i18n: env-check ## Build the language files: text-domain audit, then pot -> po -> mo (wp i18n)
	@test -f '$(TOOLS)/wpcs/vendor/bin/phpcs' || composer --working-dir='$(TOOLS)/wpcs' update
	XDEBUG_MODE=off '$(TOOLS)/wpcs/vendor/bin/phpcs' --standard=phpcs-i18n.xml
	$(WPENV) wp --require='$(TOOLING)/i18n/schema.php' i18n make-pot . languages/$(I18N_DOMAIN).pot --slug=$(PLUGIN) --domain=$(I18N_DOMAIN) \
		--exclude=$(I18N_EXCLUDE) --headers='$(I18N_HEADERS)'
	@perl -i -ne 'print unless /^"POT-Creation-Date:/' languages/$(I18N_DOMAIN).pot
	$(WPENV) wp i18n update-po languages/$(I18N_DOMAIN).pot
	$(WPENV) wp i18n make-mo languages/
	$(I18N_AFTER)

.PHONY: bump
bump: ## Bump the version: patch by default (TYPE=minor|major|beta, DRY=1 to preview)
	XDEBUG_MODE=off php '$(TOOLING)/bin/bump' $(if $(TYPE),$(TYPE),patch) $(if $(DRY),--dry-run,) --plugin-file=$(PLUGIN).php

.PHONY: compat
compat: ## Check PHP compatibility (phpcs) and WordPress compatibility (the declared floor, wp-since)
	@test -f '$(TOOLS)/phpcs/vendor/bin/phpcs' || composer --working-dir='$(TOOLS)/phpcs' update
	@test -f '$(TOOLS)/wp-since/vendor/bin/wp-since' || composer --working-dir='$(TOOLS)/wp-since' update
	@status=0; \
	XDEBUG_MODE=off '$(TOOLS)/phpcs/vendor/bin/phpcs' --standard=phpcs.xml || status=1; \
	XDEBUG_MODE=off php -d memory_limit=2G '$(TOOLS)/wp-since/vendor/bin/wp-since' check || status=1; \
	exit $$status

.PHONY: coverage
coverage: env-check ## Run the main suites with coverage, gated by the composer script (restarts wp-env with Xdebug)
	$(WPENV_START) --xdebug=coverage
	$(WPENV) env XDEBUG_MODE=coverage $(COMPOSER) test:coverage

.PHONY: coverage\:all
coverage\:all: ## The full coverage picture: every suite with coverage, then the merged table
	@$(MAKE) --no-print-directory coverage
	@$(MAKE) --no-print-directory coverage:multisite
	@$(foreach target,$(COVERAGE_ALL_EXTRA),$(MAKE) --no-print-directory $(target) &&) true
	@$(MAKE) --no-print-directory coverage:merge

.PHONY: coverage\:merge
coverage\:merge: ## Merge the suites' clover files into tests/coverage/merged.xml, and print the merged table
	@for clover in $(COVERAGE_CLOVERS); do \
		test -f "$$clover" || { printf '\nNo %s — run the coverage target that writes it first.\n\n' "$$clover"; exit 1; }; \
		find $(COVERAGE_SOURCE) -name '*.php' -newer "$$clover" | head -1 | grep -q . && \
			printf '\n\033[33mWarning:\033[0m %s/ has changed since %s was generated —\nthe merged table may show phantom uncovered lines. Regenerate it.\n' '$(COVERAGE_SOURCE)' "$$clover"; \
	done; true
	XDEBUG_MODE=off php '$(TOOLING)/bin/merge-clover' $(COVERAGE_CLOVERS) tests/coverage/merged.xml

.PHONY: coverage\:multisite
coverage\:multisite: multisite-env ## Run the multisite suite with coverage (its clover feeds coverage:merge)
	@cd $(MULTISITE_DIR) && npx @wordpress/env run cli --env-cwd=wp-content/plugins/$(PLUGIN) \
		env XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/pest \
		--test-directory=$(MULTISITE_DIR) -c $(MULTISITE_DIR)/phpunit.xml --colors=always \
		--coverage-clover=tests/coverage/multisite.xml

.PHONY: env\:info
env\:info: env-check ## What the suite is actually running against
	@printf '\nWordPress  '
	@$(WPENV) wp core version 2>/dev/null | tr -d '\r'
	@printf 'PHP        '
	@$(WPENV) php -r 'echo PHP_VERSION, PHP_EOL;' 2>/dev/null | tr -d '\r'
	@printf 'Tested to  %s (readme.txt)\n\n' "$$(perl -lne 'm{Tested up to: *(.+)} and print $$1' readme.txt)"

.PHONY: env\:update
env\:update: docker-check ## Update the pinned WordPress version in every wp-env instance to the latest release
	@latest="$$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ | perl -lne 'm{"current":"([^"]+)"} and print $$1 and exit')"; \
	test -n "$$latest" || { printf '\nCould not reach wordpress.org to ask what the latest release is.\n\n'; exit 1; }; \
	printf '\nPinning WordPress to %s\n\n' "$$latest"; \
	perl -i -pe "s{\"core\": \".*\"}{\"core\": \"https://wordpress.org/wordpress-$$latest.zip\"}" $(WPENV_CONFIGS)
	$(WPENV_START) --update
	@if [ -f $(MULTISITE_DIR)/.wp-env.json ]; then \
		(cd $(MULTISITE_DIR) && env WP_ENV_PORT=$(MULTISITE_PORT) npx @wordpress/env start --update) && \
		$(MAKE) --no-print-directory multisite-env; \
	fi
	$(ENV_UPDATE_AFTER)
	@$(MAKE) --no-print-directory env:info
	@printf 'Commit %s to pin this for everybody; CI reads the pin from them.\n\n' '$(WPENV_CONFIGS)'

.PHONY: help
help: ## Display this help
	@awk '/^[^\t#].*?:.*?##/ { \
		n = index($$0, "##"); \
		t = substr($$0, 1, n - 1); \
		gsub(/\\:/, SUBSEP, t); \
		sub(/:.*/, "", t); \
		gsub(SUBSEP, ":", t); \
		d = substr($$0, n + 2); \
		sub(/^[ \t]+/, "", d); \
		printf "\033[36m%-30s\033[0m %s\n", t, d \
	}' $(MAKEFILE_LIST) | sort

.PHONY: stubs
stubs: ## List the stub manifest: what is generated from where, and what is missing
	@XDEBUG_MODE=off php '$(TOOLING)/bin/generate-stubs' --list

# The stubs come from the latest upstream releases.
#   make stubs:update SLUGS='woocommerce elementor'
.PHONY: stubs\:update
stubs\:update: ## Regenerate the third-party stubs from tests/bin/stubs-manifest.php (SLUGS=… for a subset)
	XDEBUG_MODE=off php -d memory_limit=4G '$(TOOLING)/bin/generate-stubs' $(SLUGS)

.PHONY: test
test: env-check ## Run the plugin's JavaScript tests, then the main Pest suites inside wp-env
	$(TEST_JS)
	$(WPENV) env XDEBUG_MODE=off $(COMPOSER) test

.PHONY: test\:all
test\:all: ## Run every suite: the main ones, the multisite suite and any the plugin adds
	@$(MAKE) --no-print-directory test
	@$(MAKE) --no-print-directory test:multisite
	@$(foreach target,$(TEST_ALL_EXTRA),$(MAKE) --no-print-directory $(target) &&) true

# Run `make test` as well before committing.
#   make test:file FILE=tests/Integration/ExampleTest.php [NAME='part of the test name']
.PHONY: test\:file
test\:file: env-check ## Run one test file (FILE=…), or the tests in it matching NAME=…
	@test -n '$(FILE)' || { printf '\nUsage: make test:file FILE=tests/Integration/ExampleTest.php [NAME="part of the test name"]\n\n'; exit 1; }
	$(WPENV) env XDEBUG_MODE=off $(PEST) '$(FILE)' $(if $(NAME),--filter='$(NAME)',)

# Run `make test` as well before committing.
#   make test:files FILES="tests/Integration/A.php tests/Integration/B.php"
.PHONY: test\:files
test\:files: env-check ## Run several test files together (FILES="a.php b.php") — for bisecting a leak
	@test -n '$(FILES)' || { printf '\nUsage: make test:files FILES="tests/Integration/A.php tests/Integration/B.php"\n\n'; exit 1; }
	$(WPENV) env XDEBUG_MODE=off $(PEST) $(FILES)

.PHONY: test\:import
test\:import: env-check ## Run only the Import suite inside wp-env (runs last: it defines WP_IMPORTING)
	$(WPENV) env XDEBUG_MODE=off $(COMPOSER) test:import

.PHONY: test\:integration
test\:integration: env-check ## Run only the Integration suite inside wp-env
	$(WPENV) env XDEBUG_MODE=off $(COMPOSER) test:integration

.PHONY: test\:multisite
test\:multisite: multisite-env ## Run the multisite suite in its own wp-env instance
	@cd $(MULTISITE_DIR) && npx @wordpress/env run cli --env-cwd=wp-content/plugins/$(PLUGIN) \
		env XDEBUG_MODE=off php -d memory_limit=-1 vendor/bin/pest \
		--test-directory=$(MULTISITE_DIR) -c $(MULTISITE_DIR)/phpunit.xml --colors=always

.PHONY: test\:profile
test\:profile: env-check ## Show the slowest tests in the suite
	$(WPENV) env XDEBUG_MODE=off $(PEST) --profile

# Every suite runs with the same seed.
#   make test:random SEED=1234
.PHONY: test\:random
test\:random: env-check ## Run each suite with its tests shuffled, to find tests that leak state
	@seed="$(if $(SEED),$(SEED),$$(od -An -N2 -tu2 < /dev/urandom | tr -d ' '))"; \
	printf '\nRandom order seed: %s   (reproduce with: make test:random SEED=%s)\n' "$$seed" "$$seed"; \
	for suite in $(RANDOM_SUITES); do \
		printf '\n─── %s ───\n' "$$suite"; \
		$(WPENV) env XDEBUG_MODE=off $(PEST) --testsuite="$$suite" --order-by=random --random-order-seed="$$seed" || exit 1; \
	done

.PHONY: test\:thirdparty
test\:thirdparty: env-check ## Run only the ThirdParty suite inside wp-env
	$(WPENV) env XDEBUG_MODE=off $(COMPOSER) test:thirdparty

.PHONY: test\:unit
test\:unit: env-check ## Run only the Unit suite inside wp-env (fast feedback loop)
	$(WPENV) env XDEBUG_MODE=off $(COMPOSER) test:unit

# Runs on the host: the wp-env cli container has no git, and a source install is a git clone.
.PHONY: tooling\:dist
tooling\:dist: ## Put the released wp-plugin-tooling back in place of a git clone
	@test -z "$$(git -C '$(TOOLING)' status --porcelain 2>/dev/null)" || { \
		printf '\n%s has uncommitted changes. Commit or discard them first.\n\n' '$(TOOLING)'; \
		exit 1; \
	}
	composer reinstall pryley/wp-plugin-tooling --prefer-dist

.PHONY: tooling\:source
tooling\:source: ## Replace wp-plugin-tooling with a git clone on main, to change it alongside the plugin
	composer reinstall pryley/wp-plugin-tooling --prefer-source
	git -C '$(TOOLING)' switch main
	@printf '\n%s is a git clone on main. Commit, tag and push there, then run\n`composer update pryley/wp-plugin-tooling` here and commit the lock.\n\n' '$(TOOLING)'

.PHONY: update
update: env-check ## Update the Composer dependencies (inside wp-env) and check the NPM ones
	$(WPENV) composer update
	npm-check -u

.PHONY: watch
watch: ## Watch and rebuild the assets with Rollup
	npm run rollup:watch

# ─── Private targets ─────────────────────────────────────────────────────────
# No help entry; used only as prerequisites by the recipes above. docker-check
# is the plugin's.

.PHONY: env-check
env-check: docker-check
	@test -f vendor/bin/pest || { \
		printf '\nThe test environment has not been set up yet. Run:\n\n'; \
		printf '    make test:install\n\n'; \
		exit 1; \
	}
	@docker ps --format '{{.Names}}' | grep -Eq 'wp-env-$(PLUGIN)-[0-9a-f]+-cli' || { \
		printf '\nwp-env is not running — starting it…\n\n'; \
		$(WPENV_START); \
	}

# wp-env rewrites wp-config.php when start provisions the instance again.
# That drops the constants multisite-convert added, so the `wp config set` lines add them back.
.PHONY: multisite-env
multisite-env: docker-check
	@printf 'Preparing the multisite wp-env instance (port %s)…\n' '$(MULTISITE_PORT)'
	@log=$$(mktemp); trap 'rm -f "$$log"' EXIT; \
	quietly() { "$$@" >"$$log" 2>&1 || { cat "$$log"; exit 1; }; }; \
	$(if $(MULTISITE_PREPARE),$(MULTISITE_PREPARE);) \
	cd $(MULTISITE_DIR); \
	quietly env WP_ENV_PORT=$(MULTISITE_PORT) npx @wordpress/env start --xdebug=coverage; \
	npx @wordpress/env run cli wp core is-installed --network >/dev/null 2>&1 \
		|| quietly npx @wordpress/env run cli wp core multisite-convert --title='$(MULTISITE_TITLE)'; \
	quietly npx @wordpress/env run cli bash -c "\
		wp config set MULTISITE true --raw --type=constant && \
		wp config set SUBDOMAIN_INSTALL false --raw --type=constant && \
		wp config set DOMAIN_CURRENT_SITE localhost:$(MULTISITE_PORT) --type=constant && \
		wp config set PATH_CURRENT_SITE / --type=constant && \
		wp config set SITE_ID_CURRENT_SITE 1 --raw --type=constant && \
		wp config set BLOG_ID_CURRENT_SITE 1 --raw --type=constant"; \
	$(if $(MULTISITE_SETUP),$(MULTISITE_SETUP),true)

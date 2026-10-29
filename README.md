# wp-plugin-tooling

This package holds the development tooling that WordPress plugins share when they are tested in [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) with [Pest](https://pestphp.com). Nothing in it is specific to one plugin. Each plugin keeps its own settings, stubs and test helpers.

## Installing it

The package isn't on Packagist, so a plugin adds the repository and then requires the package in its `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/pryley/wp-plugin-tooling" }],
"require-dev": { "pryley/wp-plugin-tooling": "^1.0" }
```

It needs PHP 8.3 or later, because Pest 4 does.

## Make targets

The targets that plugins share are in `make/plugin.mk`. A plugin's `Makefile` sets its variables, defines its own `docker-check` and `test:install` targets, and then includes the file. The plugin has to define those two targets itself, because `plugin.mk` doesn't exist until `test:install` has run Composer.

```make
PLUGIN := my-plugin
TEST_DIR := tests
WP_ENV_PORT := 8888
MULTISITE_PORT := 8889
MULTISITE_TITLE := My Plugin Network
TEST_JS = npm run test:js

TOOLING_MK := vendor/pryley/wp-plugin-tooling/make/plugin.mk
ifneq ($(wildcard $(TOOLING_MK)),)
include $(TOOLING_MK)
else
.DEFAULT:
	@printf '\nRun `make test:install` first.\n\n'
endif

.PHONY: docker-check
docker-check:
	@docker info >/dev/null 2>&1 || { printf '\nThe Docker engine is not running.\n\n'; exit 1; }

.PHONY: test\:install
test\:install: docker-check ## Start wp-env and install the composer dev dependencies inside it
	@test ! -d vendor/pryley/wp-plugin-tooling/.git || test -z "$$(git -C vendor/pryley/wp-plugin-tooling status --porcelain)" || { \
		printf '\nvendor/pryley/wp-plugin-tooling has uncommitted changes. Commit or discard them first.\n\n'; exit 1; }
	rm -rf vendor
	WP_ENV_PORT=$(WP_ENV_PORT) npx @wordpress/env start
	npx @wordpress/env run cli --env-cwd=wp-content/plugins/$(PLUGIN) composer install
```

Run `make help` to see every target. It lists the shared targets and the plugin's own targets together. The shared targets run the Composer scripts `test`, `test:unit`, `test:integration`, `test:thirdparty`, `test:import` and `test:coverage`, so the plugin needs to define those scripts.

A plugin can set these variables before the include:

| Variable | Default | What it sets |
|---|---|---|
| `PLUGIN` | The folder's name | The plugin's slug, which is also the name of its main file. |
| `TEST_DIR` | `tests` | The folder that holds the Pest tests. |
| `SUITES` | `Unit Integration ThirdParty Import` | The suites that `make test:random` shuffles. Set `RANDOM_SUITES` to change only that list. |
| `WP_ENV_PORT` | `8888` | The port of the main wp-env instance. |
| `MULTISITE_DIR` | `tests/multisite` | The folder that holds the multisite suite and its wp-env config. |
| `MULTISITE_PORT` | `8889` | The port of the multisite wp-env instance. |
| `MULTISITE_TITLE` | `$(PLUGIN) Network` | The title of the test network. |
| `WPENV_CONFIGS` | `.wp-env.json` and the multisite config | The wp-env configs that `make env:update` pins to the latest WordPress. |
| `COVERAGE_CLOVERS` | `tests/coverage/clover.xml tests/coverage/multisite.xml` | The coverage files that `make coverage:merge` merges. |
| `COVERAGE_SOURCE` | `plugin` | The folder whose changes make `make coverage:merge` warn that a coverage file is out of date. |
| `I18N_DOMAIN` | `$(PLUGIN)` | The text domain. |
| `I18N_EXCLUDE` | `node_modules,tests,vendor` | The folders that make-pot skips. |
| `I18N_HEADERS` | `{}` | The headers of the .pot file, written as JSON. |

To add a step to a shared target, a plugin sets one of these hook variables to the commands it wants to run:

| Hook | When it runs |
|---|---|
| `TEST_JS` | Before the Pest suites in `make test`. |
| `TEST_ALL_EXTRA` | At the end of `make test:all`. Set it to the extra suite targets to run. |
| `COVERAGE_ALL_EXTRA` | In `make coverage:all`, before the merge. Set it to the extra coverage targets to run. |
| `ENV_UPDATE_AFTER` | After `make env:update` has updated the instances. |
| `I18N_AFTER` | After `make build:i18n` has built the .mo files. |
| `MULTISITE_PREPARE` | Before the multisite instance starts. |
| `MULTISITE_SETUP` | After the network is configured, in the multisite folder. Write `quietly <command>` to print a command's output only when it fails. |

Every target works with GNU Make 3.81, which is the version that macOS ships.

## The Pest harness

`Pryley\WpPluginTooling\Pest\Isolation` runs each test inside a database transaction and restores the hooks and the request afterwards. It fails a test that commits the transaction without saying so, and a test that defines `WP_IMPORTING` without saying so. The plugin passes in the steps that only it knows about:

```php
use Pryley\WpPluginTooling\Pest\Isolation;

$isolation = new Isolation(
    prelude: fn () => MyCache::flush(),        // runs first in beforeEach, outside the transaction
    reset: fn () => resetGlobalState(),        // runs in afterEach, after the object cache is flushed
    purge: fn () => purgeCommittedRows(),      // runs after a test that declared its commit
);
uses()->beforeEach($isolation->beforeEach())->afterEach($isolation->afterEach())->in('Unit', 'Integration');
```

A test that runs DDL must call `commitsTransaction()`. After that test, Isolation commits what it wrote last, which is usually its own cleanup, and then runs the purge. The test fails if it changed the number of rows in the posts table, the terms table, or the options table (not counting transients). An import suite should be declared last in `phpunit.xml` and call `definesWpImporting()` in its `beforeEach`.

The helper functions in `src/Pest/functions.php` use the same namespace. A suite's bootstrap calls `interceptMail()` and `blockHttpRequests()` once. Tests can then use `sentMail()`, `sentTo()`, `interceptHttp()` and `sentJson()`. The harness itself uses `backupHooks()`, `restoreHooks()` and `resetRequestState()`.

## Stubs

`bin/generate-stubs` builds a plugin's third-party stubs, which phpstan and the test suite load in place of the real plugins. It reads the plugin's manifest in `tests/bin/stubs-manifest.php` and writes the stubs to `tests/stubs`. Run `make stubs` to list the manifest, and `make stubs:update` to regenerate the stubs. Add `SLUGS='woocommerce'` to regenerate only some of them. The comment at the top of the script describes the options a manifest entry can use.

The generator never overwrites a file in `tests/stubs` that doesn't carry its header. That way a hand-written file, such as a working fake, is safe.

phpstan has to load the stubs as `bootstrapFiles`, because a plugin class that extends a stub's class can't load until the stub has run. So a plugin's phpstan config should load only one stub for each third-party plugin. If a symbol is declared twice, PHP stops with an error that names both files.

## phpstan

A plugin's `phpstan.neon` includes the base config and then adds its own paths, stubs and ignores:

```neon
includes:
    - vendor/pryley/wp-plugin-tooling/phpstan/base.neon
```

The base config sets level 5 and `treatPhpDocTypesAsCertain: false`. It also loads bleedingEdge, the WordPress extension (`szepeviktor/phpstan-wordpress`) and the WordPress constants that phpstan needs, and it ignores `argument.type` errors.

## phpcs

PHP_CodeSniffer with PHPCompatibility, WordPressCS and wp-since each have their own installation in `tools/`. They can't share the plugin's `vendor/` folder: WordPressCS needs PHP_CodeSniffer 3, while PHPCompatibility runs on version 4, and wp-since needs version 4 of `nikic/php-parser`, while Pest needs version 5.

A plugin's `phpcs.xml` references the shared ruleset and sets everything else itself:

```xml
<rule ref="vendor/pryley/wp-plugin-tooling/rulesets/compat.xml"/>
<config name="testVersion" value="8.1-"/>
```

`make compat` checks PHP compatibility with PHP_CodeSniffer and WordPress compatibility with wp-since, and `make build:i18n` checks the plugin's text domains with its `phpcs-i18n.xml` and WordPressCS. Each tool is installed inside the package's folder the first time a target runs it.

## Releases

`bin/release-check` runs the checks before a release. It stops if anything is uncommitted or if the main file's Version doesn't match the Stable tag in readme.txt. Then it asks you to confirm each item on a checklist. A plugin's release script builds the plugin, runs the check, and then deploys:

```sh
make build
php vendor/pryley/wp-plugin-tooling/bin/release-check --ask="Updated the What's New page?"
```

Each `--ask` adds one item of the plugin's own to the end of the checklist.

`make bump` changes the version in composer.json, readme.txt and the main plugin file. It makes a patch release by default. Use `TYPE=minor`, `TYPE=major` or `TYPE=beta` for another kind, and `DRY=1` to preview the change.

## Changing the package from a plugin

Run `make tooling:source` in the plugin to replace the installed release with a git clone of this repository on `main`. You can then change the package and test it with the plugin. When you're done, commit, tag and push from `vendor/pryley/wp-plugin-tooling`, then run `composer update pryley/wp-plugin-tooling` in the plugin and commit its lock file. `make tooling:dist` puts the released version back. Both targets run on your computer rather than in wp-env, because the wp-env container doesn't have git.

## Versions

Releases follow semantic versioning. A plugin's `composer.lock` records the release it was tested with, and `composer update pryley/wp-plugin-tooling` moves it to a newer one.

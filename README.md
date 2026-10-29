# wp-plugin-tooling

This package holds the development tooling that WordPress plugins share when they are tested in [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) with [Pest](https://pestphp.com). Nothing in it is specific to one plugin. Each plugin keeps its own settings, stubs and test helpers.

## Installing it

The package isn't on Packagist, so a plugin adds the repository and then requires the package in its `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/pryley/wp-plugin-tooling" }],
"require-dev": { "pryley/wp-plugin-tooling": "^1.0" }
```

It needs PHP 8.3 or later, because Pest 4 does.

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

`bin/generate-stubs` builds a plugin's third-party stubs, which phpstan and the test suite load in place of the real plugins. It reads the plugin's manifest in `tests/bin/stubs-manifest.php` and writes the stubs to `tests/stubs`. Run it from the plugin's root as `php vendor/pryley/wp-plugin-tooling/bin/generate-stubs`. Name some slugs to regenerate only those stubs, or add `--list` to show the manifest instead. The comment at the top of the script describes the options a manifest entry can use.

The generator never overwrites a file in `tests/stubs` that doesn't carry its header. That way a hand-written file, such as a working fake, is safe.

phpstan has to load the stubs as `bootstrapFiles`, because a plugin class that extends a stub's class can't load until the stub has run. So a plugin's phpstan config should load only one stub for each third-party plugin. If a symbol is declared twice, PHP stops with an error that names both files.

## Releases

`bin/release-check` runs the checks before a release. It stops if anything is uncommitted or if the main file's Version doesn't match the Stable tag in readme.txt. Then it asks you to confirm each item on a checklist. A plugin's release script builds the plugin, runs the check, and then deploys:

```sh
make build
php vendor/pryley/wp-plugin-tooling/bin/release-check --ask="Updated the What's New page?"
```

Each `--ask` adds one item of the plugin's own to the end of the checklist.

`bin/bump` changes the version in composer.json, readme.txt and the main plugin file. Run it from the plugin's root as `php vendor/pryley/wp-plugin-tooling/bin/bump`. It makes a patch release by default. Name `minor`, `major` or `beta` for another kind, and add `--dry-run` to preview the change.

## Versions

Releases follow semantic versioning. A plugin's `composer.lock` records the release it was tested with, and `composer update pryley/wp-plugin-tooling` moves it to a newer one.

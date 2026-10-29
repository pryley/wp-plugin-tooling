# wp-plugin-tooling

This package holds the development tooling that WordPress plugins share when they are tested in [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) with [Pest](https://pestphp.com). Nothing in it is specific to one plugin. Each plugin keeps its own settings, stubs and test helpers.

## Installing it

The package isn't on Packagist, so a plugin adds the repository and then requires the package in its `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/pryley/wp-plugin-tooling" }],
"require-dev": { "pryley/wp-plugin-tooling": "^1.0" }
```

It needs PHP 8.3 or later, because Pest 4 does.

## Stubs

`bin/generate-stubs` builds a plugin's third-party stubs, which phpstan and the test suite load in place of the real plugins. It reads the plugin's manifest in `tests/bin/stubs-manifest.php` and writes the stubs to `tests/stubs`. Run it from the plugin's root as `php vendor/pryley/wp-plugin-tooling/bin/generate-stubs`. Name some slugs to regenerate only those stubs, or add `--list` to show the manifest instead. The comment at the top of the script describes the options a manifest entry can use.

The generator never overwrites a file in `tests/stubs` that doesn't carry its header. That way a hand-written file, such as a working fake, is safe.

phpstan has to load the stubs as `bootstrapFiles`, because a plugin class that extends a stub's class can't load until the stub has run. So a plugin's phpstan config should load only one stub for each third-party plugin. If a symbol is declared twice, PHP stops with an error that names both files.

## Releases

`bin/bump` changes the version in composer.json, readme.txt and the main plugin file. Run it from the plugin's root as `php vendor/pryley/wp-plugin-tooling/bin/bump`. It makes a patch release by default. Name `minor`, `major` or `beta` for another kind, and add `--dry-run` to preview the change.

## Versions

Releases follow semantic versioning. A plugin's `composer.lock` records the release it was tested with, and `composer update pryley/wp-plugin-tooling` moves it to a newer one.

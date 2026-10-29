# Changelog

## 1.0.0 (unreleased)

- Added `bin/bump`, which moves the version in composer.json, readme.txt and the main plugin file
- Added `bin/generate-stubs`, which generates a plugin's third-party stubs from its manifest
- Added `bin/merge-clover`, which merges coverage files and prints the merged table
- Added `bin/release-check`, the checks and checklist before a release
- Added `Isolation`, the per-test isolation of a plugin's Pest suite, with the mailbox, HTTP and hook helpers
- Added `phpstan/base.neon`, the phpstan settings a plugin starts from
- Added `tools/`, the isolated PHP_CodeSniffer, WordPressCS and wp-since installs

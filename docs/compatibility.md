---
layout: default
title: Compatibility
---

# Compatibility

What Molly needs from the application, and which combinations the release tests cover.

## The application

| Requirement | Supported |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| PHP extensions | DOM, PDO, PDO SQLite |
| Database | Any working Laravel connection |
| Git | Required. Molly records the revision each run started from. |
| Pest | Pest 4 with `pest-plugin-laravel` 4 is tested. Pest 5 is accepted by doctor but not yet part of release testing. |

## What the release tests cover

| Check | Coverage |
| --- | --- |
| Package test suite | PHP 8.3, 8.4, and 8.5 on the locked dependencies, plus the lowest and highest dependency sets Composer will resolve |
| Fresh application install | New Laravel 12 and 13 applications: install, publish config, migrate, create and read a task, index Laravel knowledge, confirm `--no-dev` leaves Molly out |
| Jev | The package suite against the accepted Laravel AI classification commit, with the live-capability tests required to run |
| Sandbox | The Landlock sandbox tests run on Linux and skip on macOS |

The fresh-application job installs the checked-out package. It does not run a model.

## Installing Pest 4

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer remove --dev phpunit/phpunit
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
vendor/bin/pest --init
```

Laravel 12 applications pin PHPUnit 11, and Pest 4 needs PHPUnit 12, so the `composer remove` line clears that pin. Skip it when `composer.json` does not list `phpunit/phpunit`.

## The sandbox

On Linux, Molly isolates the writer and the Pest process with Landlock and user and network namespaces. macOS cannot, so doctor reports `sandbox_unavailable` there; [Getting started](getting-started.md#macos-and-the-sandbox) explains the override.

## Next

- [Getting started](getting-started.md)
- [Troubleshooting](troubleshooting.md)

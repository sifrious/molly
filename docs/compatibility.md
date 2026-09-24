---
layout: default
title: Compatibility
---

# Compatibility

Molly requires Pest to be available in the host Laravel application. This page lists what current release testing covers, so you can tell a tested combination from an untested one.

## Host application

| Requirement | Supported |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| PHP extensions | DOM, PDO, PDO SQLite |
| Database | Any working Laravel connection |
| Git | Required. Molly binds evidence to the checked-out revision. |

## What CI proves

| Check | Coverage |
| --- | --- |
| Molly's package test suite | PHP 8.3, 8.4 and 8.5, running Pest 4 |
| Fresh consumer install | New Laravel 12 and 13 apps on PHP 8.4: install, publish config, migrate, create and read a task, index Laravel knowledge |

The fresh-consumer job installs the checked-out package source. It does not run a model or a Pest task inside the consumer app.

## Pest in your app

`molly:doctor` checks that `vendor/bin/pest` exists in the workspace. It does not require a particular Pest major version.

| Pest in the host app | Status |
| --- | --- |
| Pest 4 with `pest-plugin-laravel` 4 | Tested. The QuickStart and the demo installer use it. |
| Pest 5 | Accepted by `molly:doctor`. A new Laravel 13 app created with `--pest` can ship Pest 5. A full Molly task run on Pest 5 is not yet part of release testing. |

If you are unsure, install Pest 4:

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer remove --dev phpunit/phpunit
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
```

Laravel 12 apps pin PHPUnit 11 in `composer.json`, and Pest 4 needs PHPUnit 12, so the second line removes that pin first. Pest installs the PHPUnit version it needs. If your app does not list `phpunit/phpunit`, skip that line.

## Sandbox

The safe workflow isolates the writer and the Pest verifier with Landlock and Linux user and network namespaces. `molly:doctor` reports whether the host supports them. macOS does not. See [Troubleshooting](troubleshooting.md#sandbox-unavailable).

---
layout: default
title: QuickStart — Molly standalone
---

# QuickStart: Molly standalone

This is the default way to use Molly. You work from the terminal in an existing Laravel app.

**Bloom is not required.** If you later want Bloom's workspace and review UI, read [QuickStart: Molly + Bloom](quickstart-bloom.md) after this page.

You finish with three things: `molly:doctor` passes, a demo task has run, and you know where to read its evidence before accepting the change.

## Before you start

You need:

- PHP 8.3 or later with the DOM and PDO SQLite extensions
- Laravel 12 or 13
- Pest installed in the app (see [Compatibility](compatibility.md) for tested versions)
- A working Laravel database connection
- Git, because Molly binds evidence to the checked-out revision
- [Ollama](https://ollama.com) running locally, or the Amp CLI

Check the app first:

```bash
php --version
composer show laravel/framework
vendor/bin/pest --version
vendor/bin/pest
```

If the app has no Pest yet:

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer remove --dev phpunit/phpunit
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
./vendor/bin/pest --init
```

Laravel 12 apps pin PHPUnit 11 in `composer.json`, and Pest 4 needs PHPUnit 12, so the second line removes that pin first. Pest installs the PHPUnit version it needs. If your app does not list `phpunit/phpunit`, skip that line.

For Laravel feature tests, `tests/Pest.php` should extend your application test case:

```php
<?php

pest()->extend(Tests\TestCase::class)->in('Feature');
```

## Copy-paste first run

From the Laravel project root:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

The first line points Composer at the public GitHub repository because Molly is not listed on Packagist yet. `^0.1.1` is the current tagged release line. This page switches to the v1 constraint only after v1 is tagged and a fresh install is proven.

Add Molly's local files to `.gitignore`:

```gitignore
.molly/
```

Molly is a development dependency. Production installs that use `composer install --no-dev` do not include it.

## What each step does

| Step | Result |
| --- | --- |
| `vendor:publish` and `migrate` | Adds `config/molly.php` and Molly's task and run tables. |
| `ollama pull` | Installs a local coding model. `qwen2.5-coder:7b` is a starter suggestion and needs roughly 8 GB of free memory. Any local model works. |
| `molly:setup` | Saves the agent and model. It does not store secrets. |
| `molly:doctor` | Checks Pest, the database, the agent, the sandbox, and Clever. Continue only when it prints `Molly is ready.` |
| `molly:demo` | Writes `app/Greeting.php` and `tests/Feature/GreetingTest.php` when they are missing and saves the task `demo-greeting`. |
| `molly:start` | Asks the agent for a change, applies allowed-file edits, runs Pest, runs Tarpit, and records Clever measurements. |

Molly will not fall back to a hosted provider if Ollama is unreachable. Doctor fails instead.

### Prefer Amp?

```bash
php artisan molly:setup --agent=amp
amp mcp approve molly
amp mcp doctor molly
php artisan config:clear
php artisan molly:doctor
```

Amp keeps its own login. Molly stores only the provider choice. The same file limits and verification rules apply to both agents.

### If doctor reports `sandbox_unavailable`

Molly isolates the writer and the Pest verifier with Landlock and Linux user and network namespaces when the host supports them. macOS does not, so doctor usually reports `sandbox_unavailable` there and `molly:start` refuses the safe workflow.

Run the safe workflow on a Linux host with those features. The local override exists for diagnostics in a trusted checkout only. It is described in [Troubleshooting](troubleshooting.md#sandbox-unavailable) and is not part of this QuickStart.

## Inspect the result

`molly:start` prints a run ID. Read the evidence before you accept anything:

```bash
php artisan molly:task demo-greeting
php artisan molly:show RUN_ID --verbose
php artisan molly:receipt RUN_ID
git diff
vendor/bin/pest
```

A completed run means Molly has valid required evidence for that task. It does not mean the whole application is correct, and Molly does not commit for you.

Check that:

- the required Pest test ran and passed,
- its assertions prove the behavior you asked for,
- Tarpit has no unresolved blocking finding,
- the changed files match the task you intended,
- your broader test suite still passes.

## If the attempt fails

Read the failed evidence first:

```bash
php artisan molly:show RUN_ID --verbose
php artisan molly:advice demo-greeting
```

If another attempt makes sense:

```bash
php artisan molly:retry demo-greeting
```

Molly keeps earlier attempts. The default limit is three attempts in total, and Molly never retries on its own.

## Your own first task

```bash
php artisan molly:create
php artisan molly:start TASK_NAME
```

For example:

| Question | Example answer |
| --- | --- |
| What should Molly work on? | `Add GET /molly-health returning exactly {"status":"ok"}. Preserve existing routes.` |
| Task nickname | `health-check` |
| Which Pest test should pass? | `tests/Feature/MollyHealthTest.php` |
| Which other files may Molly change? | `routes/web.php` |

Creating a task does not call the model or edit files.

## Next

- [Manage tasks](tasks.md)
- [Understand verification](verification.md)
- [Local Ollama details](ollama-quickstart.md)
- [Local web interface](web-interface.md)
- [QuickStart: Molly + Bloom](quickstart-bloom.md)

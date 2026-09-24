---
layout: default
title: Getting started
---

# Getting started

This page takes you from an empty terminal to a completed Molly task. You will install Molly into a Laravel application, point it at a local model, run the demo task, and read the evidence it produced. Nothing here needs Bloom or a paid AI account.

## Requirements

- PHP 8.3 or later with the DOM, PDO, and PDO SQLite extensions
- Laravel 12 or 13
- Pest 4 in the application
- Git, because Molly records which revision each run started from
- A working database connection
- [Ollama](https://ollama.com) running locally, or the Amp CLI

To check the application:

```bash
php --version
composer show laravel/framework
vendor/bin/pest --version
```

If the application does not have Pest yet, install it:

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer remove --dev phpunit/phpunit
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
vendor/bin/pest --init
```

Laravel 12 applications pin PHPUnit 11, and Pest 4 needs PHPUnit 12, so the `composer remove` line clears that pin first. Skip it if `composer.json` does not list `phpunit/phpunit`.

Feature tests need the application test case. Check that `tests/Pest.php` contains:

```php
pest()->extend(Tests\TestCase::class)->in('Feature');
```

[Compatibility](compatibility.md) lists the tested versions.

### No Laravel application yet?

The demo installer creates a fresh application, installs Pest and the tagged Molly release, and scaffolds the demo task:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.3/bin/molly-demo -o molly-demo
bash molly-demo ~/molly-demo
```

It writes only under the directory you name and refuses a non-empty directory unless you pass `--force`. When it finishes, continue from [Choose a model](#choose-a-model).

## Install Molly

From the application root:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Molly is not on Packagist yet, so the first line tells Composer to read tagged releases from GitHub. Publishing adds `config/molly.php`. The migration adds the tables that hold tasks and runs.

Add Molly's local files to `.gitignore`:

```gitignore
.molly/
/storage/molly/
```

Molly is a development dependency. A production `composer install --no-dev` does not include it, and its commands do not register in production.

## Choose a model

Molly sends the change request to a local Ollama model by default. Pull a model and tell Molly its exact name:

```bash
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
```

`molly:setup` writes `MOLLY_AGENT` and `MOLLY_LOCAL_MODEL` to `.env`. It stores no secrets. Any model from `ollama list` works; `qwen2.5-coder:7b` is a starting point that needs about 8 GB of free memory. If Ollama is unreachable, Molly stops rather than falling back to a hosted service.

Small models handle the demo. They often cannot write a Pest test file or answer Molly's review questions on their own, so a larger model is worth the download for real work. [Ollama](ollama-quickstart.md) has the details and the doctor codes.

To use Amp instead, see [Agents](agents.md#amp).

## Check the setup

```bash
php artisan molly:doctor
```

Doctor checks the database tables, Pest, the sandbox, the model, and the Clever measurements. When everything passes it prints:

```text
Molly is ready.
```

A failed check prints a code and what to do about it. [Troubleshooting](troubleshooting.md#doctor-fails) lists every code.

### macOS and the sandbox

Molly runs the model's edits and the Pest process inside a Linux sandbox (Landlock plus user and network namespaces) so a proposal can touch only the files you allowed. macOS has no Landlock, so doctor reports `sandbox_unavailable` and `molly:start` refuses to run.

To run Molly on a Mac, turn isolation off for a checkout you trust:

```dotenv
MOLLY_SANDBOX_ALLOW_UNSAFE=true
```

Then run `php artisan config:clear` and `php artisan molly:doctor` again. Doctor now reports `sandbox_unsafe_override`. Without the sandbox, the writer and Pest run with your user's permissions, so keep this to repositories you control. Molly still checks every proposed path against the allowed files before it applies anything.

## Run the demo task

```bash
php artisan molly:demo
php artisan molly:start demo-greeting
```

`molly:demo` writes `app/Greeting.php`, a Pest test at `tests/Feature/GreetingTest.php` that fails against it, and saves a task named `demo-greeting`. It does not call the model.

`molly:start` does the work in your terminal:

1. Asks the model for a change to `app/Greeting.php`.
2. Checks that the proposal touches only that file and leaves the test alone.
3. Applies the proposal.
4. Runs `tests/Feature/GreetingTest.php` with Pest.
5. Asks the model to review the diff with Molly's seven complexity checks (Tarpit).
6. Records Clever measurements of the code before and after.

The task completes only when Pest passes and Tarpit finds nothing blocking. The command prints the run ID and exits with `0` on completion and `1` otherwise.

## Read the evidence

Read the run before you accept the change:

```bash
php artisan molly:task demo-greeting
php artisan molly:show RUN_ID --verbose
git diff
vendor/bin/pest
```

`molly:task` shows the task and every attempt. `molly:show --verbose` shows the Pest output, the Tarpit findings, and the measurements. `git diff` shows exactly what changed. Molly does not commit for you.

A completed run means the required test passed and the review found nothing blocking. It does not mean the whole application is fine, which is why the last command runs your full suite.

## If the run failed

Read why first:

```bash
php artisan molly:show RUN_ID --verbose
```

The verbose report names the failing assertion, the Tarpit finding, or the process error. If another attempt makes sense:

```bash
php artisan molly:retry demo-greeting
```

Molly keeps the earlier run and starts a new one. A task may make three attempts by default, and Molly never retries on its own. `php artisan molly:advice demo-greeting` explains which action is allowed next.

## Create your own task

```bash
php artisan molly:create
```

Molly asks four questions:

| Question | Example |
| --- | --- |
| What should Molly work on? | `Add GET /ready returning exactly {"ready":true}. Preserve existing routes.` |
| Task nickname | `ready-check` |
| Which Pest test should pass? | `tests/Feature/ReadyTest.php` |
| Which other files may Molly change? | `routes/web.php` |

The Pest test must exist before you create the task, and Molly locks its content. The model may change the files you listed, never the test. Creating a task saves it without calling the model.

Start it when you are ready:

```bash
php artisan molly:start ready-check
```

If you would rather have Molly write the Pest test first, [Tutorials](tutorials.md#write-the-test-first) shows the two-task flow.

## Next

- [Tasks](tasks.md) covers starting, stopping, retrying, and naming tasks.
- [Verification](verification.md) explains what must pass and what happens when it does not.
- [Molly on its own](standalone.md) tours everything available without Bloom.
- [Web interface](web-interface.md) shows the same records in a browser.

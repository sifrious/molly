# Getting started

This page takes you from an empty terminal to a completed Molly task. You will install Molly into a Laravel application, point it at a local model, run the demo task, and read the evidence it produced. Nothing here needs Bloom or a paid AI account.

## Requirements

- PHP 8.3 or later with the DOM, PDO, and PDO SQLite extensions
- Laravel 12 or 13
- Pest 4 in the application
- Git, with the application committed to a repository, because Molly records which commit each run started from
- A working database connection
- [Ollama](https://ollama.com) running locally, or the Amp CLI

To check each requirement from the application root:

```bash
php --version
php -m | grep -Ei '^(dom|pdo_sqlite)$'
composer show laravel/framework
vendor/bin/pest --version
git rev-parse HEAD
php artisan migrate:status
ollama list
```

`php -m` should print `dom` and `pdo_sqlite`. `git rev-parse HEAD` prints a commit SHA once the application has one commit; [Install Molly](#install-molly) shows how to make it. `php artisan migrate:status` fails when the database connection does not work. `ollama list` fails when Ollama is not running; start it with `ollama serve` or the Ollama app. With Amp instead, `command -v amp` must print a path; `molly:doctor` checks the Amp login later.

If the application does not have Pest yet, install it:

```bash
composer remove --dev phpunit/phpunit --no-update
composer require --dev pestphp/pest:^4.7 pestphp/pest-plugin-laravel:^4.1 -W
./vendor/bin/pest --init
```

New Laravel 12 and 13 applications list `phpunit/phpunit` (12 pins PHPUnit 11, and Pest 4 needs PHPUnit 12), so the first line removes it without resolving, and `-W` lets the second line resolve Pest and PHPUnit together. These applications already allow the `pestphp/pest-plugin` Composer plugin; an older application may need `composer config allow-plugins.pestphp/pest-plugin true` first. `./vendor/bin/pest --init` writes `tests/Pest.php`.

Feature tests need the application test case. Check that `tests/Pest.php` applies `Tests\TestCase` to the `Feature` directory. The file `./vendor/bin/pest --init` writes does it with these lines:

```php
use Tests\TestCase;

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');
```

[Compatibility](compatibility.md) lists the tested versions.

### No Laravel application yet?

The demo installer creates a fresh application, commits it once with your own Git identity, installs Pest and the tagged Molly release, and scaffolds the demo task:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.3/bin/molly-demo -o molly-demo
MOLLY_CONSTRAINT='^0.2' bash molly-demo ~/molly-demo
```

The v0.1.3 installer defaults to the 0.1 series, so `MOLLY_CONSTRAINT` selects the 0.2 series. Until `v0.2.0` is tagged, that constraint does not resolve and the installer stops at `composer require`; use `MOLLY_CONSTRAINT='^0.1'` for the tagged release, or see [Release status](#release-status).

It writes only under the directory you name and refuses a non-empty directory unless you pass `--force`. When it finishes, continue from [Choose a model](#choose-a-model).

## Install Molly

### Release status

The latest tag is `v0.1.3`. Molly 0.2 is in prelaunch acceptance testing, and no 0.2 release is tagged yet, so `composer require --dev sifrious/molly:^0.2` fails until `v0.2.0` is published. Until then you have two choices:

- Install an acceptance candidate, named `0.2.0-RC<n>`, from the zip you were given. [Install a prelaunch candidate](quickstart-standalone.md#install-a-prelaunch-candidate) has the commands.
- Install the tagged release with `composer require --dev sifrious/molly:^0.1`. It lacks the behavior that [Molly documentation](overview.md) lists as arriving after `v0.1.3`.

These pages describe `main`, which `v0.2.0` will publish.

### Install from GitHub

From the application root:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.2
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Molly is not on Packagist yet, so the first line tells Composer to read tagged releases from GitHub. Publishing adds `config/molly.php`. The migration adds the tables that hold tasks and runs.

Add Molly's local files to `.gitignore`:

```gitignore
.molly/
/storage/molly/
```

`molly:project-init` adds both lines when they are missing.

Molly never creates a repository or a commit in your application. `composer create-project` does not create one either, so if `git status` says the directory is not a Git repository, commit the application yourself before you create a task:

```bash
git init
git add -A
git commit -m "Start"
```

Without a repository, `molly:demo` and `molly:create` stop with `WORKSPACE_NOT_GIT` and write nothing. See [Not a Git repository](troubleshooting.md#not-a-git-repository).

Molly is a development dependency. A production `composer install --no-dev` does not include it, and its commands do not register in production.

## Choose a model

Molly sends the change request to a local Ollama model by default. Install Ollama 0.34.4, then let Molly decide which approved model fits this Mac and install it:

```bash
php artisan molly:preflight
php artisan molly:install-model
php artisan molly:setup --agent=ollama --model=MODEL
php artisan config:clear
```

`molly:preflight` prints the fit decision and downloads nothing. On a 96 GB Mac it selects `gpt-oss:120b-code`, on a Mac with 16 GB or more `gpt-oss:20b`, and on a smaller Mac it prints `No supported local Ollama configuration fits this Mac.` When a run would refuse to load an installed model with the memory available now, it reports `memory_unavailable` and selects nothing. `molly:install-model` shows the download size and the volume, asks before it downloads, verifies the model, and runs a readiness check that includes a small Molly task. It prints the `molly:setup` line to run; replace `MODEL` with that name. `molly:setup` writes `MOLLY_AGENT` and `MOLLY_LOCAL_MODEL` to `.env`. It stores no secrets. If Ollama is unreachable, Molly stops rather than falling back to a hosted service.

You can still select any installed model with `molly:setup --model=NAME`. Small models often cannot write a Pest test file or answer Molly's review questions, which is why the readiness check includes both a change and a review. [Ollama](ollama-quickstart.md) has the details and the doctor codes.

To use Amp instead, see [Agents](agents.md#amp).

## Check the setup

```bash
php artisan molly:doctor
```

Doctor checks the database tables, Pest, Git and the application's repository, the sandbox, the model, and the Clever measurements. When everything passes it prints:

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

A task needs a Pest test that fails until the change exists. Create `tests/Feature/ReadyTest.php`:

```php
<?php

it('reports that the application is ready', function () {
    $this->get('/ready')->assertOk()->assertExactJson(['ready' => true]);
});
```

Run it and confirm it fails with a 404, not an error in the test:

```bash
vendor/bin/pest tests/Feature/ReadyTest.php
```

Then create the task:

```bash
php artisan molly:create
```

Molly asks four questions:

| Question | Example |
| --- | --- |
| What should Molly work on? | `Add GET /ready returning exactly {"ready":true}. Preserve existing routes.` |
| Task nickname | `ready-check` |
| Which Pest test should pass? | `tests/Feature/ReadyTest.php` |
| Which files may Molly change? | `routes/web.php` |

The same task without prompts:

```bash
php artisan molly:create 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.' \
  --name=ready-check \
  --test=tests/Feature/ReadyTest.php \
  --file=routes/web.php
```

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

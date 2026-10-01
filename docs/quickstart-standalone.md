# QuickStart: Molly on its own

The shortest path from a Laravel application to a completed Molly task, without Bloom or a paid AI account. [Getting started](getting-started.md) explains each step; this page is the copy-paste version.

From the application root, with [Ollama](https://ollama.com) installed. New Laravel 12 and 13 applications ship PHPUnit, so install Pest first if `vendor/bin/pest` does not exist:

```bash
composer remove --dev phpunit/phpunit --no-update
composer require --dev pestphp/pest:^4.7 pestphp/pest-plugin-laravel:^4.1 -W
./vendor/bin/pest --init
```

Molly records the commit each task starts from and never creates a repository for you. If the application is not a Git repository yet, commit it first:

```bash
git init
git add -A
git commit -m "Start"
```

Then install Molly and run the demo:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.2
php artisan vendor:publish --tag=molly-config
php artisan migrate
php artisan molly:preflight
php artisan molly:install-model
php artisan molly:setup --agent=ollama --model=MODEL
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

`molly:install-model` asks before it downloads and prints the model it installed; use that name for `MODEL`. [Ollama](ollama-quickstart.md#the-fit-decision) explains how Molly chooses it.

On macOS, doctor reports `sandbox_unavailable`. Add `MOLLY_SANDBOX_ALLOW_UNSAFE=true` to `.env` for a checkout you trust, then clear the configuration cache and run doctor again; [Getting started](getting-started.md#macos-and-the-sandbox) says what that trades away.

Then read the result:

```bash
php artisan molly:show RUN_ID --verbose
git diff
vendor/bin/pest
```

To build a feature from a story instead of the demo, let Molly write the test, lock it, and implement it. `molly:story` prints the same three commands with the task name filled in:

```bash
php artisan molly:story 'Guests see "Hello stranger". Signed-in users see "Hello world".' --test=tests/Feature/HelloTest.php
php artisan molly:start TASK
php artisan molly:lock-test TASK --approve
php artisan molly:start TASK
```

Run any `composer require` command that `molly:story` prints before the last command. [Tasks](tasks.md#start-from-a-story) explains each step.

No Laravel application yet? The demo installer creates one:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.3/bin/molly-demo -o molly-demo
MOLLY_CONSTRAINT='^0.2' bash molly-demo ~/molly-demo
```

The v0.1.3 installer defaults to the 0.1 series, so `MOLLY_CONSTRAINT` selects the 0.2 series. Until `v0.2.0` is tagged, that constraint does not resolve; [Release status](getting-started.md#release-status) says what to install until then.

## Install a prelaunch candidate

Acceptance testers install a candidate build instead of a tagged release. Point Composer at the directory that holds the candidate zip and `candidate.json`, then require that exact version:

```bash
composer config repositories.molly-candidate artifact ~/molly-acceptance/0.2.0-RC1
composer require --dev sifrious/molly:0.2.0-RC1
```

Use the directory and version of the candidate you were given. [Acceptance](acceptance/README.md) explains how candidates are built and checked.

Next: [Getting started](getting-started.md), [Tasks](tasks.md), [Molly on its own](standalone.md).

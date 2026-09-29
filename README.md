# Molly

[![Package tests](https://github.com/sifrious/molly/actions/workflows/tests.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/tests.yml)
[![HOL Guard](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml)

Molly makes a small change to a Laravel application and proves it with Pest before the task can be called done. You name the change, the files an agent may edit, and the Pest test that must pass. Molly asks a local model for the change, applies it, runs the test, reviews the diff for needless complexity, and records the evidence. The model never decides whether its own work passed.

Molly runs from Artisan, has a local web page for reading evidence, and works without Bloom.

## Install

Molly needs PHP 8.3 or later, Laravel 12 or 13, Pest in the application, Git, and a working database connection. From the application root:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.2
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Molly is not on Packagist yet, so the first line tells Composer where the tagged releases live. Molly is a development dependency, so `composer install --no-dev` leaves it out of production.

New Laravel 12 and 13 applications ship PHPUnit, not Pest. If `vendor/bin/pest` does not exist, install Pest before you run Molly:

```bash
composer remove --dev phpunit/phpunit --no-update
composer require --dev pestphp/pest:^4.7 pestphp/pest-plugin-laravel:^4.1 -W
./vendor/bin/pest --init
```

The first line drops the PHPUnit requirement without updating yet, the second installs Pest and lets Composer pick the PHPUnit version Pest needs, and `--init` adds `tests/Pest.php`.

Add Molly's local files to `.gitignore`:

```gitignore
.molly/
/storage/molly/
```

Molly records the commit each task starts from, so the application must be a Git repository with at least one commit. Molly never runs `git init` or commits for you. For a fresh `composer create-project` app, run `git init && git add -A && git commit -m "Start"` before `molly:demo`; otherwise Molly stops with `WORKSPACE_NOT_GIT`.

## Run the demo with a local model

You do not need a paid AI account. With [Ollama](https://ollama.com) installed:

```bash
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

`molly:preflight` reports what this Mac can hold before you download a model; it measures and never downloads. `molly:doctor` prints `Molly is ready.` when the database, Pest, the model, and the sandbox check out. `molly:demo` writes a small greeting class and a failing Pest test, then saves a task called `demo-greeting`. `molly:start` asks the model for the change, applies it, runs the test, and prints a run ID.

On macOS, doctor reports that the writer sandbox is unavailable, because it needs Linux Landlock. Molly refuses to start a task until you opt out of isolation for a trusted checkout. [Getting started](docs/getting-started.md#macos-and-the-sandbox) explains that choice.

Read the result before you accept it:

```bash
php artisan molly:show RUN_ID --verbose
git diff
vendor/bin/pest
```

Molly does not commit anything. If the run failed, `php artisan molly:retry demo-greeting` starts another attempt, up to three in total.

## Your first real task

```bash
php artisan molly:create
```

Molly asks what should change, which Pest test must pass, and which files it may edit. Creating a task saves it without calling the model. Start it with `php artisan molly:start TASK`.

## Documentation

Start with [Getting started](docs/getting-started.md). Then:

- [Tasks](docs/tasks.md), [Verification](docs/verification.md), and [Troubleshooting](docs/troubleshooting.md)
- [Molly on its own](docs/standalone.md) and [Molly with Bloom](docs/bloom.md)
- [Tutorials](docs/tutorials.md) for the longer workflows, including [Pest todos from a GitHub issue](docs/github-todos.md) and [a custom Laravel AI step](docs/customize-steps.md)
- [Commands](docs/reference/commands.md) and [Configuration](docs/reference/configuration.md)

The full index is in the [documentation overview](docs/overview.md).

## Status

Molly 0.2 is in prelaunch acceptance testing. The latest tag is `v0.1.3`; no 0.2 release is tagged yet, so the `^0.2` install line above resolves only after `v0.2.0` is published. Release candidates, named `0.2.0-RC<n>`, are acceptance builds installed from a local zip, not releases; [docs/acceptance/README.md](docs/acceptance/README.md) describes them. These docs follow `main`, which `v0.2.0` will publish. It covers bounded coding tasks, protected acceptance tests, Pest verification, Tarpit review, Clever measurements, bounded retries, Pest todos from GitHub issue acceptance criteria, local Ollama and Amp agents, agent steps you can replace with your own Laravel AI agent class, local MCP tools, planning, a local web interface, project and Laravel knowledge graphs, journals, and optional Jev advice through Laravel AI.

Molly does not open or merge pull requests. Remote execution on an Orb is not shipped: Molly refuses Orb requests, and every run executes on the machine where you run Artisan. The Bloom plugin is built against Bloom commit `1599f05f` and is not in the Composer package; host-level acceptance of the Bloom integration is pending. Every workflow also works from Artisan and the local web interface.

## Name and license

Molly is named for Molly Bloom in *Ulysses*, whose chapter begins and ends with yes. Molly's yes has to be earned. The thinking behind Tarpit and Clever comes from Mary Perry's Laracon US 2026 talk, [Cleverness Is A Loan](https://www.youtube.com/watch?v=vsxoaTgtyjw).

Molly is available under the [MIT license](LICENSE). See [SECURITY.md](SECURITY.md) for supported versions and private reporting.

See [AI ownership](docs/reference/ai-ownership.md) for the Laravel AI v1 boundary, the responsibilities Molly keeps, and migration checks.

# Molly

[![Package tests](https://github.com/sifrious/molly/actions/workflows/tests.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/tests.yml)
[![HOL Guard](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml)

Molly makes a small change to a Laravel application and proves it with Pest before the task can be called done. You name the change, the files an agent may edit, and the Pest test that must pass. Molly asks a local model for the change, applies it, runs the test, reviews the diff for needless complexity, and records the evidence. The model never decides whether its own work passed.

Molly runs from Artisan, has a local web page for reading evidence, and works without Bloom.

## Install

Molly needs PHP 8.3 or later, Laravel 12 or 13, Pest in the application, Git, and a working database connection. From the application root:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Molly is not on Packagist yet, so the first line tells Composer where the tagged releases live. Molly is a development dependency, so `composer install --no-dev` leaves it out of production.

Add Molly's local files to `.gitignore`:

```gitignore
.molly/
/storage/molly/
```

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

`molly:doctor` prints `Molly is ready.` when the database, Pest, the model, and the sandbox check out. `molly:demo` writes a small greeting class and a failing Pest test, then saves a task called `demo-greeting`. `molly:start` asks the model for the change, applies it, runs the test, and prints a run ID.

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
- [Tutorials](docs/tutorials.md) for the longer workflows
- [Commands](docs/reference/commands.md) and [Configuration](docs/reference/configuration.md)

The full index is in the [documentation overview](docs/overview.md).

## Status

Molly is a `0.1.x` release; these docs follow `main`, which the next tag will publish. It covers bounded coding tasks, protected acceptance tests, Pest verification, Tarpit review, Clever measurements, bounded retries, local Ollama and Amp agents, local MCP tools, planning, a local web interface, project and Laravel knowledge graphs, journals, and optional Jev advice through Laravel AI.

Molly does not open or merge pull requests. Remote execution on an Orb is planned and not shipped. The Bloom desktop integration is unfinished; every workflow works from Artisan and the local web interface.

## Name and license

Molly is named for Molly Bloom in *Ulysses*, whose chapter begins and ends with yes. Molly's yes has to be earned. The thinking behind Tarpit and Clever comes from Mary Perry's Laracon US 2026 talk, [Cleverness Is A Loan](https://www.youtube.com/watch?v=vsxoaTgtyjw).

Molly is available under the [MIT license](LICENSE). See [SECURITY.md](SECURITY.md) for supported versions and private reporting.

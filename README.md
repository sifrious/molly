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

`molly:project-init` adds both lines when they are missing.

Molly records the commit each task starts from, so the application must be a Git repository with at least one commit. Molly never runs `git init` or commits for you. For a fresh `composer create-project` app, run `git init && git add -A && git commit -m "Start"` before `molly:demo`; otherwise Molly stops with `WORKSPACE_NOT_GIT`.

## Run the demo with a local model

You do not need a paid AI account. With [Ollama](https://ollama.com) 0.34.4 installed:

```bash
php artisan molly:preflight
php artisan molly:install-model
php artisan molly:setup --agent=ollama --model=MODEL
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

`molly:preflight` measures this Mac and decides which approved model fits: `gpt-oss:120b-code` on a 96 GB Mac, `gpt-oss:20b` on a Mac with 16 GB or more, or no fit. It never selects an installed model that a run would refuse to load with the memory available now, and says so with `memory_unavailable`. It downloads nothing. `molly:install-model` shows the download size and the volume it goes to, asks before it downloads, verifies the model's digest, and checks that the model can do a Molly task. Replace `MODEL` with the name it prints. [Ollama](docs/ollama-quickstart.md) explains the decision and the approved catalogue. `molly:doctor` prints `Molly is ready.` when the database, Pest, the model, and the sandbox check out. It fails with `model_exceeds_memory` when the model plus `molly.memory.headroom_gb` is larger than the memory preflight measures, and a run refuses that model with `MODEL_MEMORY_INSUFFICIENT` before Ollama loads it. `molly:demo` writes a small greeting class and a failing Pest test, then saves a task called `demo-greeting`. `molly:start` asks the model for the change, applies it, runs the test, and prints a run ID.

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
- [Tutorials](docs/tutorials.md) for the longer workflows, including [Pest todos from a GitHub issue](docs/github-todos.md), [a custom Laravel AI step](docs/customize-steps.md), and [two tasks on two local Orbs](docs/execution-targets.md#run-tasks-on-two-local-orbs)
- [Commands](docs/reference/commands.md) and [Configuration](docs/reference/configuration.md)

The full index is in the [documentation overview](docs/overview.md).

## Status

Molly 0.2 is in prelaunch acceptance testing. The latest tag is `v0.1.3`; no 0.2 release is tagged yet, so the `^0.2` install line above resolves only after `v0.2.0` is published. Release candidates, named `0.2.0-RC<n>`, are acceptance builds installed from a local zip, not releases; [docs/acceptance/README.md](docs/acceptance/README.md) describes them. These docs follow `main`, which `v0.2.0` will publish. It covers bounded coding tasks, protected acceptance tests, Pest verification, Tarpit review, Clever measurements, bounded retries, Pest todos from GitHub issue acceptance criteria, local Ollama and Amp agents, agent steps you can replace with your own Laravel AI agent class, local MCP tools, local Orbs that run tasks in separate Git worktrees, planning, a local web interface, project and Laravel knowledge graphs, change-impact overlays, learning paths, journals, and optional Jev advice through Laravel AI.

Integration status: machine acceptance has not passed. The supplied RC10 (`d0f4e6e`) result reports rejection with 33 of 165 mandatory subcases accepted. The later source tip `b34928d` and this combined tree have no acceptance verdict established by this reconciliation. The [acceptance matrix](docs/acceptance/matrix.md) remains historical RC9-era reporting.

Molly does not open or merge pull requests. MCP clients cannot approve a change, lock a test, record a pull request or merge, post a GitHub comment, or hand off a task; a person runs those Artisan commands with `--approve`. A run executes on the machine where you run Artisan, or on a local Orb: a registered worker on the same machine with its own ID, model, and Git worktree per task, so two Orbs can run two tasks at once. Hosted Orbs on another machine are not shipped; see [Execution targets](docs/execution-targets.md). The Bloom plugin is built against Bloom commit `1599f05f` and is not in the Composer package; host-level acceptance of the Bloom integration is pending. Every workflow also works from Artisan and the local web interface.

## Name and license

Molly is named for Molly Bloom in *Ulysses*, whose chapter begins and ends with yes. Molly's yes has to be earned. The thinking behind Tarpit and Clever comes from Mary Perry's Laracon US 2026 talk, [Cleverness Is A Loan](https://www.youtube.com/watch?v=vsxoaTgtyjw).

Molly is available under the [MIT license](LICENSE). See [SECURITY.md](SECURITY.md) for supported versions and private reporting.

See [AI ownership](docs/reference/ai-ownership.md) for the Laravel AI v1 boundary, the responsibilities Molly keeps, and migration checks.

# Molly

[![Package tests](https://github.com/sifrious/molly/actions/workflows/tests.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/tests.yml)
[![HOL Guard](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml)

Molly makes a small change to a Laravel application and proves it with Pest before the task can be called done. You name the change, the files the model may edit, and the Pest test that must pass. Molly asks a local model for the change, applies it, runs the test, reviews the diff for needless complexity, and records the evidence. Pest and that review decide whether the work passed.

Molly runs from Artisan and has a local web page for the same records. You can use it without Bloom, the optional macOS app.

## Install and run the demo

Molly needs PHP 8.3 or later with the DOM, PDO, and PDO SQLite extensions, a Laravel 12 or 13 application, Pest, Git, and a working database connection. Molly is a development dependency, so `composer install --no-dev` leaves it out of production. Molly is not on Packagist yet.

`v0.2.0` is not tagged. `composer require --dev sifrious/molly:^0.2` fails until that tag exists. Until then, install a checksummed candidate zip: one commit packaged for Composer, with its SHA-256 in `candidate.json`. The zip for the Bloom result in [Status](#status) is release candidate 18, commit `6bb6fb26`, version `0.2.0-RC18`. From a checkout of this repository:

```bash
bin/molly-candidate 6bb6fb26 0.2.0-RC18 --out ~/molly-acceptance/0.2.0-RC18
```

That command writes `sifrious-molly-6bb6fb26.zip` and `candidate.json`. The zip is 693,843 bytes. Its SHA-256 is `c80fe219af360fdc83ae2a032ef04f8221157a8068af878f6e496e864756f820`. Compare the file with the `sha256` field in `candidate.json` before you install it. [Install the candidate](docs/acceptance/README.md#install-the-candidate) describes the same files.

Add Molly's local files to `.gitignore`. `php artisan molly:project-init` adds both lines when they are missing, and it leaves a candidate that is already installed in place.

```gitignore
.molly/
/storage/molly/
```

From the Laravel application root:

1. Install Pest when `vendor/bin/pest` does not exist. New Laravel 12 and 13 applications ship PHPUnit. The first command drops that requirement without updating yet. The second installs Pest and lets Composer choose the PHPUnit version Pest needs. `--init` writes `tests/Pest.php`.

   ```bash
   composer remove --dev phpunit/phpunit --no-update
   composer require --dev pestphp/pest:^4.7 pestphp/pest-plugin-laravel:^4.1 -W
   ./vendor/bin/pest --init
   ```

2. Commit the application when `git rev-parse HEAD` fails. Molly records the commit each task starts from, and it never runs `git init` or commits for you. Without a commit, `molly:demo` stops with `WORKSPACE_NOT_GIT` and writes nothing.

   ```bash
   git init
   git add -A
   git commit -m "Start"
   ```

3. Point Composer at the directory that holds the zip, and require the version from `candidate.json`. Use your own directory and version when they differ from this example.

   ```bash
   composer config repositories.molly-candidate artifact ~/molly-acceptance/0.2.0-RC18
   composer require --dev sifrious/molly:0.2.0-RC18
   ```

4. Publish Molly's config and migrate. Migrate uses the application's database connection.

   ```bash
   php artisan vendor:publish --tag=molly-config
   php artisan migrate
   ```

5. On a Mac, turn the Linux file sandbox off for a checkout you trust. The sandbox uses Landlock, and doctor reports `sandbox_unavailable` when the host cannot provide it. `molly:start` then refuses to run. Add this line to `.env`:

   ```dotenv
   MOLLY_SANDBOX_ALLOW_UNSAFE=true
   ```

   Run `php artisan config:clear`. The doctor command in the next step reports `sandbox_unsafe_override` for that check. The writer and Pest then run with your user's permissions. Molly still refuses a change outside the files you allowed. On Linux, skip this step. [Getting started](docs/getting-started.md#macos-and-the-sandbox) uses the same setting.

6. Install [Ollama](https://ollama.com) 0.34.4 and let Molly choose a model. `molly:preflight` measures this Mac and decides which approved model fits: `gpt-oss:120b-code` on a 96 GB Mac, `gpt-oss:20b` on a Mac with 16 GB or more, or no fit. It downloads nothing. When a run would refuse a model that is already installed, preflight says `memory_unavailable` and selects nothing. `molly:install-model` shows the download size, asks before it downloads, and checks that the model can do a Molly task. Replace `MODEL` with the name it prints. `molly:setup` writes `MOLLY_AGENT` and `MOLLY_LOCAL_MODEL` to `.env`. `molly:doctor` prints `Molly is ready.` when the database, Pest, the model, and the sandbox check out. Doctor reports `model_exceeds_memory` when the model plus `molly.memory.headroom_gb` is larger than the memory preflight measured, and a run refuses that model with `MODEL_MEMORY_INSUFFICIENT`. [Ollama](docs/ollama-quickstart.md) explains the choice.

   ```bash
   php artisan molly:preflight
   php artisan molly:install-model
   php artisan molly:setup --agent=ollama --model=MODEL
   php artisan config:clear
   php artisan molly:doctor
   ```

7. Scaffold the demo and start it. `molly:demo` writes `app/Greeting.php`, a failing Pest test at `tests/Feature/GreetingTest.php`, and a task named `demo-greeting`. It does not call the model. `molly:start` asks the model for the change, applies it, runs the test, and prints a run ID.

   ```bash
   php artisan molly:demo
   php artisan molly:start demo-greeting
   ```

8. Read the result before you keep it. Molly does not commit anything. If the run failed, `php artisan molly:retry demo-greeting` starts another attempt. A task may make three attempts.

   ```bash
   php artisan molly:show RUN_ID --verbose
   git diff
   vendor/bin/pest
   ```

After `v0.2.0` is tagged, install the release with these lines in place of step 3:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.2
```

## Your own task

`php artisan molly:create` asks what should change, which Pest test must pass, and which files Molly may edit. Creating a task saves it and does not call the model. Start it with `php artisan molly:start TASK`.

## Documentation

Start with [Getting started](docs/getting-started.md). Then:

- [Tasks](docs/tasks.md), [Verification](docs/verification.md), and [Troubleshooting](docs/troubleshooting.md)
- [Molly on its own](docs/standalone.md) and [Molly with Bloom](docs/bloom.md)
- [Tutorials](docs/tutorials.md), including [Pest todos from a GitHub issue](docs/github-todos.md), [a custom Laravel AI step](docs/customize-steps.md), and [two tasks at once, each in its own Git worktree](docs/execution-targets.md#run-tasks-on-two-local-orbs) (Molly calls each local worker an Orb)
- [Commands](docs/reference/commands.md) and [Configuration](docs/reference/configuration.md)
- [Editable instruction packs](docs/seams.md), for a controller or Artisan command test you can edit

The full index is in the [documentation overview](docs/overview.md).

## Status

Molly 0.2 is in prelaunch. The latest tag is `v0.1.3`, and these pages describe the current source, which you install from the candidate zip above until `v0.2.0` is published. From Artisan, that source can ask a local model for a small change, run the Pest test you named, and save the run. The native Bloom checks (M04, the desktop app screens) passed 16 of 16 on release candidate 18, commit `6bb6fb26`. The Studio record is [pull request 130, comment 6161166682](https://github.com/sifrious/molly/pull/130#issuecomment-6161166682).

## Name and license

Molly is named for Molly Bloom in *Ulysses*, whose chapter begins and ends with yes. Molly's yes has to be earned: the Pest test has to pass. The seven review questions (Tarpit) and the before-and-after measurements (Clever) come from Mary Perry's Laracon US 2026 talk, [Cleverness Is A Loan](https://www.youtube.com/watch?v=vsxoaTgtyjw). Clever records numbers. It does not combine them into a score.

Molly is available under the [MIT license](LICENSE). See [SECURITY.md](SECURITY.md) for supported versions and private reporting.

See [AI ownership](docs/reference/ai-ownership.md) for what Molly keeps when it uses Laravel AI, and the checks to run when that package changes.

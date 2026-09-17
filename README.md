# Molly

Give Molly a small coding task and choose the files Molly may change. Molly asks a local Ollama model for edits, runs a required Pest test file, and reviews the changes for unnecessary complexity. The terminal shows test evidence and complexity findings together.

Molly is a Laravel package in development. The local demo runs one task at a time. No alpha release is tagged yet.

## Install in a local application

Start in a trusted, disposable checkout of a Laravel 13 application with PHP 8.4 or later, the DOM extension, and Pest 4 installed. Keep the application's normal database configuration and use `APP_ENV=local`. Start Ollama before checking the environment.

If the application does not already use Pest, install Pest and its Laravel plugin:

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
```

Add this binding to `tests/Pest.php` so feature tests boot the application. Keep any existing test setup in that file:

```php
<?php

pest()->extend(Tests\TestCase::class)->in('Feature');
```

Molly and Clever currently install from their public Git repositories. Run these commands in the application:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer config repositories.clever vcs https://github.com/sifrious/cleverness
composer require --dev sifrious/molly:dev-main maryperry/clever:dev-main
php artisan vendor:publish --tag=molly-config
php artisan migrate
ollama list
```

Choose a locally installed model that supports structured output. Set `MOLLY_LOCAL_MODEL` in the application's `.env` to the exact model name from `ollama list`. Molly does not download models or switch to a paid provider.

Set the local endpoint in `.env`:

```dotenv
OLLAMA_URL=http://127.0.0.1:11434
```

Add `.molly/` to the workspace's `.gitignore`. Molly uses `.molly/run.lock` to prevent two Molly runs from editing the same workspace at once.

```bash
php artisan config:clear
php artisan molly:doctor
```

Resolve failed checks before running a task. Doctor checks the database table, workspace Pest installation, local provider configuration, installed model, and host application's Clever configuration. Clever enables itself in `local` and `testing` by default and stays disabled in production.

## Run a task

Name every file Molly may change with `--file`. Include the required test file in that list, then select the same file with `--test`:

```bash
php artisan molly:run \
  'Add GET /health returning JSON {"status":"ok"}. Add a Pest feature test for the status code and exact JSON.' \
  --file=routes/web.php \
  --file=tests/Feature/HealthTest.php \
  --test=tests/Feature/HealthTest.php
```

Omit the prompt to answer an interactive Laravel Prompts question. Use `--workspace=/path/to/checkout` to select another workspace. Paths passed to `--file` and `--test` stay relative to that workspace. The selected workspace must have its own Pest installation. Run history and evidence remain in the host Laravel application.

For scripts, provide the prompt and request JSON:

```bash
php artisan molly:run \
  'Add GET /health returning JSON {"status":"ok"} and test the response.' \
  --file=routes/web.php \
  --file=tests/Feature/HealthTest.php \
  --test=tests/Feature/HealthTest.php \
  --json --no-interaction

php artisan molly:doctor --json
```

`molly:run --json` writes one JSON object containing `id`, `status`, and `report`. Both commands exit nonzero on failure. A missing prompt fails when `--json` or `--no-interaction` is set.

## Read the result

Molly measures complexity before editing. The writer receives the task, the selected files, and the required test path. Molly validates the proposed paths, applies the edits, runs Pest, requests a Tarpit review, and measures complexity again.

A completed task requires changed files, passing Pest evidence, a complete Tarpit review without blocking findings, and usable Clever reports. Molly also checks that the selected files still match the reviewed contents. Missing Clever or a failed scan stops the run. A skipped Clever probe remains visibly skipped and does not count as a passed measurement, but a skipped probe alone does not block completion.

Pest must report at least one executed test. Failures, errors, skipped or incomplete tests, risky tests, warnings, missing JUnit evidence, and timeouts prevent completion. Molly runs only the selected test file, not the application's full suite. The Pest child process removes inherited variables named in the host environment file so the workspace can load its own PHPUnit and environment settings.

Tarpit review covers seven questions:

| Check | What the model reviews |
| --- | --- |
| A | Stored values that could be computed without synchronization |
| B | I/O or mutation mixed with domain decisions |
| C | Application decisions inside CLI, HTTP, or rendering code |
| D | Hidden setup or call-order requirements |
| E | Unused code or abstractions without a current requirement |
| F | Long functions, large files, deep nesting, and duplication |
| G | Caches or indexes that change required behavior or spread dependencies |

Findings name a file, line, problem, and suggested change. The review distinguishes essential complexity, justified tradeoffs, and accidental complexity. An unresolved accidental finding can block completion. The model reviews only supplied files and cannot prove whole-repository safety.

Clever reports four separate measurements. The commands are also available directly, as documented in the [Clever package guide](https://clever.mary.win/package):

```bash
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

Owned diff counts code, comment, and blank lines in configured application paths. Welds counts direct construction and static call sites, with Laravel facades reported separately. Lonely files reports single-author PHP files above its minimum size. Hotspots compares Git churn with current code lines. Git-dependent probes skip when the checkout has no commits.

Clever's default owned paths omit `tests` and `resources/views`. Read each probe's scope, caveats, and skipped status before drawing conclusions. Molly does not combine the measurements into a score, and fewer lines alone do not prove a better design.

The `molly_runs` database table stores the run and report. Evidence files live under `storage/molly/{run-id}` in the host application. Reports contain selected paths, content hashes, test output, review findings, and Clever results. Read a saved report with `php artisan molly:show RUN_ID`. The terminal compares measurements before and after the edit. Add `--verbose` to show full Clever details and hand-verification commands, or `--json` for the stored report. `molly:run` also accepts `--verbose`. This command does not run the model again. A successful lookup exits zero even when the saved run failed. An unknown ID exits nonzero.

## Configuration

Publish `config/molly.php` with the installation command above. The source defaults live in `config/molly.php` in this repository.

| Key | Default | Purpose and consumer |
| --- | --- | --- |
| `molly.model` | Required | Local Ollama model name from `MOLLY_LOCAL_MODEL`. `src/Agents/LocalOllama.php` validates the choice; `src/Actions/GenerateChanges.php` and `src/Actions/ReviewChanges.php` use the model. |
| `molly.timeout` | `180` | Positive integer timeout in seconds for each model request. The writer and reviewer consume this value. |
| `molly.test_timeout` | `120` | Pest process timeout in seconds, bounded to 1 through 3600 by `src/Actions/VerifyChanges.php`. |
| `molly.max_files` | `8` | Maximum selected file count, checked by `src/Workspace.php`. |
| `molly.max_file_bytes` | `65536` | Maximum bytes in each selected file and proposed replacement, checked by `src/Workspace.php`. |
| `ai.providers.ollama.driver` | `ollama` | Laravel AI provider driver. Molly requires `ollama`. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Local Ollama endpoint from `OLLAMA_URL`. Molly permits HTTP loopback addresses without credentials, a query, or an extra path. |

Only `molly.model` has a Molly-specific environment variable. Edit the other Molly settings in the published configuration file. Laravel AI owns the Ollama provider settings. Clever owns `clever.enabled`, `clever.root`, and `clever.report.path`. During a scan, `src/Actions/MeasureComplexity.php` sets Clever's root to the workspace and its report path to the run's evidence directory, then restores both settings.

## Limits of the demo

Use a trusted, disposable checkout. The file allowlist confines writer proposals. Pest executes PHP with the current user's permissions, so the allowlist is not a process sandbox. The selected test file is writable by the model. Review the resulting assertions and diff before accepting the change.

Failed verification or review leaves applied edits in place for inspection. Molly does not commit changes. An interrupted process can leave a run marked `running`; that state is uncertain and does not mean success. Automatic recovery and resume are not implemented.

Runtime parallel agents, Bloom integration, GitHub issue import, a web interface, remote execution, and TypeSafe evaluation are outside this demo.

## Terms

| Term | Meaning | Implementation |
| --- | --- | --- |
| Task | The prompt and explicitly selected files for one request | `src/Actions/RunTask.php` |
| Run | A persisted attempt with `running`, `completed`, or `failed` status and a report | `src/Models/Run.php` |
| Workspace | The checkout containing selected files and the per-workspace lock | `src/Workspace.php` |
| Verification | Pest execution and the JUnit evidence required for completion | `src/Actions/VerifyChanges.php` |
| Tarpit review | The model's seven checks for complexity in the supplied files | `src/Agents/TarpitReviewer.php`, `src/Actions/ReviewChanges.php` |
| Clever measurement | A probe result with metrics and limitations, separate from the review decision | `src/Actions/MeasureComplexity.php` |

## Develop Molly

```bash
composer install
vendor/bin/pest
vendor/bin/pint --format agent
```

The package tests use Pest and Orchestra Testbench. Laravel AI fakes test model responses without requiring Ollama. A live demo still requires an installed local model and a host application with Clever.

The first live check used PHP 8.4.23, Laravel 13.32.0, Laravel AI 0.11.2, and the local `gpt-oss:120b-code` model. Molly updated a named health route and its Pest test, then completed the workflow in 25 seconds. Pest passed one test with three assertions. The review returned all seven Tarpit checks without findings, and all four Clever probes returned results before and after the edit. This verifies a small CLI task. It does not establish reliability across larger tasks or other models.

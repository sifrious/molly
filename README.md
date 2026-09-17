# Molly

Give Molly a small coding task and choose the files Molly may change. Molly asks a local Ollama model for edits, runs a required Pest test file, and reviews the changes for unnecessary complexity. The terminal shows test evidence and complexity findings together.

Molly is a Laravel package in development. You can run a one-off prompt or save a task with attempt history, bounded retries, and stop requests. Molly permits one writing run per workspace. No alpha release is tagged yet.

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

Molly includes the Clever measurements and commands. Install Molly from the public repository:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:dev-main
php artisan vendor:publish --tag=molly-config
php artisan migrate
ollama list
```

Choose a locally installed model that supports structured output. Set `MOLLY_LOCAL_MODEL` in the application's `.env` to the exact model name from `ollama list`. Molly does not download models or switch to a paid provider.

Set the local endpoint in `.env`:

```dotenv
OLLAMA_URL=http://127.0.0.1:11434
```

Add `.molly/` to the workspace's `.gitignore`. Molly uses `.molly/run.lock` to prevent two runs from editing the same workspace at once. Task execution also holds `.molly/task-{task-id}.lock` through state transitions and result persistence.

```bash
php artisan config:clear
php artisan molly:doctor
```

Resolve failed checks before running a task. Doctor checks task and run tables, the workspace Pest installation, local provider configuration, installed model, and bundled measurements. Measurements enable themselves in `local` and `testing` by default and stay disabled in production.

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

## Save and manage tasks

Create a task before execution when you want attempt history and retry or stop controls:

```bash
php artisan molly:create \
  'Add GET /ready returning JSON {"ready":true}. Test the response.' \
  --file=routes/web.php \
  --file=tests/Feature/ReadyTest.php \
  --test=tests/Feature/ReadyTest.php

php artisan molly:tasks --limit=20
php artisan molly:task TASK_ID
php artisan molly:start TASK_ID
```

Creation validates the scope and saves a pending task. Creation does not call a model or edit selected files. Starting a task creates a linked run and executes the same checks as `molly:run`. The existing `molly:run` command remains a one-off command whose report is available through `molly:show`.

```bash
php artisan molly:retry TASK_ID
php artisan molly:stop TASK_ID
```

Retry accepts failed or stopped tasks. Each retry creates a new run and preserves earlier evidence. `molly.max_attempts` limits a task to three attempts by default, including the first run. Molly never retries automatically. Repeated starts cannot execute the same task concurrently. Rejected retries leave the existing task unchanged.

Stop ends a pending task immediately. For an active run, stop saves a request that execution checks between stages and after generation, before applying the proposal. Stop does not interrupt an in-flight model request or Pest process. Applied edits remain available for review.

If a process exited before saving its final status, `molly:stop` can settle the interrupted task and running attempt once both task and workspace locks are free. Saved evidence remains available. A busy or invalid lock never counts as proof that a process stopped. These locks require runners to share the local filesystem. You can then retry within the attempt limit.

All task commands accept `--json`. Create, import, show-task, and stop return `id`, `status`, and `task`. List returns `tasks`. Start and retry return `id`, `task_id`, `status`, and `report`; only completed runs exit zero. Successful lookups and stop requests exit zero regardless of the saved task status. Errors exit nonzero.

## Import a GitHub issue

Install and sign in to the GitHub CLI if you want to import an issue. GitHub access is optional for all other Molly commands.

```bash
php artisan molly:import https://github.com/OWNER/REPOSITORY/issues/NUMBER \
  --file=app/Example.php \
  --file=tests/Feature/ExampleTest.php \
  --test=tests/Feature/ExampleTest.php
```

Import reads the issue through `gh api` and creates a pending task. Import does not start execution or write to GitHub. The task records the repository, issue number and URL, title, update time, labels, and a null linked-PR value. The issue body becomes task context. Issue text is not verification evidence.

Molly accepts HTTPS `github.com` issue URLs without query strings or fragments. Pull requests and mismatched response identities are rejected. Issues exceeding the 8192-byte prompt limit fail without truncation. Create a task with a smaller scope for a larger issue. GitHub-to-Pest todo generation is not implemented yet.

## Read the result

Molly measures complexity before editing. The writer receives the task, the selected files, and the required test path. Molly validates the proposed paths, applies the edits, runs Pest, requests a Tarpit review, and measures complexity again.

A completed task requires changed files, passing Pest evidence, a complete Tarpit review without blocking findings, and usable Clever reports. Molly also checks that the selected files still match the reviewed contents. Disabled measurements or a failed scan stops the run. A skipped Clever probe remains visibly skipped and does not count as a passed measurement, but a skipped probe alone does not block completion.

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

Clever reports four separate measurements. Molly ships these commands, adapted from [Clever](https://clever.mary.win/package):

```bash
php artisan clever:scan --json
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

Owned diff counts code, comment, and blank lines in configured application paths. Welds counts direct construction and static call sites, with Laravel facades reported separately. Lonely files reports single-author PHP files above its minimum size. Hotspots compares Git churn with current code lines. Git-dependent probes skip when the checkout has no commits.

The bundled measurements' default owned paths omit `tests` and `resources/views`. Read each probe's scope, caveats, and skipped status before drawing conclusions. Molly does not combine the measurements into a score, and fewer lines alone do not prove a better design.

The `molly_runs` database table stores the run and report. Evidence files live under `storage/molly/{run-id}` in the host application. Reports contain selected paths, content hashes, test output, review findings, and Clever results. Read a saved report with `php artisan molly:show RUN_ID`. The terminal compares measurements before and after the edit. Add `--verbose` to show full Clever details and hand-verification commands, or `--json` for the stored report. `molly:run` also accepts `--verbose`. This command does not run the model again. A successful lookup exits zero even when the saved run failed. An unknown ID exits nonzero.

## Configuration

The `molly-config` publish tag copies `config/molly.php` and `config/molly-complexity.php`. The source defaults live at the same repository-relative paths.

| Key | Default | Purpose and consumer |
| --- | --- | --- |
| `molly.model` | Required | Local Ollama model name from `MOLLY_LOCAL_MODEL`. `src/Agents/LocalOllama.php` validates the choice; `src/Actions/GenerateChanges.php` and `src/Actions/ReviewChanges.php` use the model. |
| `molly.timeout` | `180` | Positive integer timeout in seconds for each model request. The writer and reviewer consume this value. |
| `molly.test_timeout` | `120` | Pest process timeout in seconds, bounded to 1 through 3600 by `src/Actions/VerifyChanges.php`. |
| `molly.max_attempts` | `3` | Maximum runs per saved task, including the first attempt. Accepts integers 1 through 10. `src/Actions/StartTask.php` checks the limit before changing task state. |
| `molly.max_files` | `8` | Maximum selected file count, checked by `src/Workspace.php`. |
| `molly.max_file_bytes` | `65536` | Maximum bytes in each selected file and proposed replacement, checked by `src/Workspace.php`. |
| `ai.providers.ollama.driver` | `ollama` | Laravel AI provider driver. Molly requires `ollama`. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Local Ollama endpoint from `OLLAMA_URL`. Molly permits HTTP loopback addresses without credentials, a query, or an extra path. |

Laravel AI owns the Ollama provider settings. `MOLLY_LOCAL_MODEL` selects the model. `MOLLY_COMPLEXITY_ENABLED` controls bundled measurements outside production. Edit the remaining settings in the published files.

| Complexity key | Default | Purpose |
| --- | --- | --- |
| `molly-complexity.enabled` | `null` | Enable in local and testing by default. `MOLLY_COMPLEXITY_ENABLED` may override outside production. Production always disables measurements. |
| `molly-complexity.root` | `null` | Measure the host application unless a workspace root is supplied. |
| `molly-complexity.report.path` | `null` | Write standalone reports to `storage/molly/complexity/report.json`. Task measurements use a unique path beneath the run's evidence directory. |
| `molly-complexity.probes` | Four bundled probe classes | Select measurements to execute. An empty set cannot complete a task. |
| `molly-complexity.owned_diff.paths` | `app`, `bootstrap`, `config`, `database`, `routes`, `resources/js` | Select directories for owned-code and history measurements. |
| `molly-complexity.owned_diff.extensions` | `php`, `js`, `ts`, `jsx`, `tsx`, `vue`, `css`, `json` | Select source extensions for owned-code counts. |
| `molly-complexity.welds.paths` | `app` | Select PHP directories for construction and static-call counts. |
| `molly-complexity.welds.facades` | `[]` | Add facade names to the reported facade classification. |
| `molly-complexity.welds.max_sites` | `200` | Limit reported call-site details. |
| `molly-complexity.lonely.min_lines` | `30` | Exclude smaller files from the lonely-files list. |
| `molly-complexity.lonely.limit` | `10` | Limit listed single-author files. |
| `molly-complexity.churn.since` | `24 months ago` | Select the Git history window. |
| `molly-complexity.churn.limit` | `20` | Limit hotspot entries. |
| `molly-complexity.exclude` | `[]` | Exclude additional directories from source enumeration. |

`src/Complexity/Support/CleverConfig.php` consumes these settings. During a task scan, `src/Actions/MeasureComplexity.php` selects the workspace and evidence path, then restores the host settings. Standalone `clever:*` commands use the configured host root. No separate Clever package or Clever configuration file is required.

If an application previously installed `maryperry/clever` only for Molly, remove that package after updating Molly to avoid duplicate `clever:*` commands. Move any custom `clever.*` settings to `molly-complexity.*`.

## Limits of the demo

Use a trusted, disposable checkout. The file allowlist confines writer proposals. Pest executes PHP with the current user's permissions, so the allowlist is not a process sandbox. The selected test file is writable by the model. Review the resulting assertions and diff before accepting the change.

Failed verification or review leaves applied edits in place for inspection. Molly does not commit changes. An interrupted process can leave a run marked `running`; that state is uncertain and does not mean success. Saved tasks support explicit interruption settlement through `molly:stop`. One-off runs have no task controls. Automatic resume is not implemented.

Runtime parallel branches, Bloom integration, GitHub-to-Pest todo generation, a web interface, approval controls, remote execution, and TypeSafe evaluation remain outside this build.

## Terms

| Term | Meaning | Implementation |
| --- | --- | --- |
| Task | A saved prompt, selected files, source context, and lifecycle state | `src/Models/Task.php`, `src/Actions/CreateTask.php` |
| Run | A persisted attempt with `running`, `completed`, `failed`, or `stopped` status and a report | `src/Models/Run.php` |
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

The package tests use Pest and Orchestra Testbench. Laravel AI fakes test model responses without requiring Ollama. A live demo requires an installed local model and a host application. Molly supplies the complexity commands.

The first live check used PHP 8.4.23, Laravel 13.32.0, Laravel AI 0.11.2, and the local `gpt-oss:120b-code` model. Molly updated a named health route and its Pest test, then completed the workflow in 25 seconds. Pest passed one test with three assertions. The review returned all seven Tarpit checks without findings, and all four Clever probes returned results before and after the edit. This verifies a small CLI task. It does not establish reliability across larger tasks or other models.

The saved-task workflow also passed a live run after removing the separate Clever package. Molly created and started a task, saved its completed run, and verified a readiness endpoint with one Pest test and three assertions. A real read-only GitHub import preserved issue identity and created a pending task. That import check did not execute the issue.

## Source credit

The bundled measurements derive from Clever commit `650a32a595036ef610a7c7af1fad4869b23ef05a` in [sifrious/cleverness](https://github.com/sifrious/cleverness). Molly preserves the original MIT notice and source reference in `src/Complexity/LICENSE.md`. Molly does not copy Clever's web interface or require the original package.

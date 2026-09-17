# Molly

Give Molly a small coding task and choose the files Molly may change. Molly asks your selected Amp agent or local Ollama model for edits, runs a required Pest test file, and reviews the changes for unnecessary complexity. The terminal shows test evidence and complexity findings together.

Molly is a development dependency for Laravel applications. You can plan a collection of tasks, consult cited offline guidance, and save tasks with attempt history, bounded retries, and stop requests. Molly permits one writing run per workspace. No alpha release is tagged yet.

## Install in a local application

Start in a trusted, disposable checkout of a Laravel 13 application with PHP 8.3 or later, the DOM extension, and Pest 4 installed. Keep the application's normal database configuration and use `APP_ENV=local`. Start Ollama for local inference, or use an authenticated Amp CLI.

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

Choose a locally installed model that supports structured output. Set `MOLLY_LOCAL_MODEL` in the application's `.env` to the exact model name from `ollama list`. Molly does not download models or silently switch providers. Amp is used only after you select it.

Set the local endpoint in `.env`:

```dotenv
OLLAMA_URL=http://127.0.0.1:11434
```

Add `.molly/` to the workspace's `.gitignore`. Molly uses `.molly/run.lock` to prevent two runs from editing the same workspace at once. Task execution also holds `.molly/task-{task-id}.lock` through state transitions and result persistence.

```bash
php artisan config:clear
php artisan molly:doctor
```

Resolve failed checks before running a task. Doctor checks task and run tables, the workspace Pest installation, the selected provider and account or installed local model, bundled measurements, and the POSIX functions required for parallel checks. Measurements enable themselves in `local` and `testing` by default and stay disabled in production.

Parallel checks require PHP's `posix_setsid` and `posix_kill` functions. Doctor reports a failed check if either function is unavailable. Enable those functions, or set `'parallel_checks' => false` in the published `config/molly.php` to run Pest and Tarpit review serially. Run `php artisan config:clear` and `php artisan molly:doctor` after changing that setting. Molly never silently falls back to serial execution.

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

## Plan before creating tasks

A project means a collection of tasks. Start with an outcome and choose whether to walk through Tarpit review:

```bash
php artisan molly:plan 'Let people review and retry failed tasks from a NativePHP mobile app.'
php artisan molly:plan --resume=PLAN_ID
```

The five questions cover the required outcome, essential data, existing Laravel behavior, justified boundaries, and passing evidence. Answers save after each question. Planning does not execute code. Use `--skip-review` to record that the review was skipped. The web interface also exposes planning and a browsable source graph. A ready plan can create several pending tasks; each task keeps a snapshot of the planning decisions and citation metadata.

For an agent or script:

```bash
php artisan molly:plan 'Show pending tasks.' --json --no-interaction
php artisan molly:plan --resume=PLAN_ID --step=outcome --answer='Show pending tasks on one screen.' --json --no-interaction
```

`src/PlanningGuide.php` reads `resources/planning/guide.json` and the bundled passages without network requests. Topic matching selects references to consult; a match does not establish that a package or abstraction belongs in the design. Each question links to its supporting sources. The graph contains selected Laravel 13 passages, Mary Perry's public Tarpit material, and separate NativePHP Desktop v2 and Mobile v4 summaries. Citations retain the original URL, revision, and content digest. [Source permissions and limits](resources/planning/MANIFEST.md) describe the bundle. Check installed package versions before using an API. A new guide version requires a new guided plan; saved answers remain available.

## Connect an agent

Choose Amp or an installed local Ollama model:

```bash
php artisan molly:setup --agent=amp
php artisan molly:chat

php artisan molly:setup --agent=ollama --model=YOUR_INSTALLED_MODEL
```

Amp setup launches the official `amp login` command in an interactive terminal, then adds a workspace MCP connection. Amp owns the login credentials. Molly saves only the selected agent and local model settings in the host application's `.env`. Noninteractive setup prints the login command instead of waiting for a browser login. `--no-login` configures the connection without launching login. Setup reports Amp's approval and connection-check commands; a saved connection does not prove authentication succeeded. See [Amp's MCP documentation](https://ampcode.com/docs/customize/mcp).

When `MOLLY_AGENT=amp`, proposal generation and Tarpit review use the authenticated Amp CLI. Molly invokes each request in a temporary directory with tools, MCP connections, and IDE access disabled. The response parser requires an empty tool list and an explicit successful terminal result. The same file-scope and structured-review validation used for Ollama applies before accepting the response. File contents selected for the task are sent to Amp. This is local execution using Amp's service, not remote Orb execution. The CLI does not always report its model, so Molly records an unknown model rather than guessing. [Amp settings](https://ampcode.com/docs/cli/settings) and [streaming JSON](https://ampcode.com/docs/cli/streaming-json) describe the CLI contract.

`molly:chat` opens Amp's existing terminal interface with a session-level Molly MCP configuration. Amp keeps its other configured servers. The MCP connection starts `php artisan mcp:start molly` over stdio. Molly registers this server only in local and testing environments, with no HTTP MCP route.

The server exposes `molly_guide`, `molly_plan`, and `molly_task`. The guide tool reads the graph or one full bundled source. The plan tool creates, reads, answers, and evaluates a plan. The task tool creates, imports, reads, queues, retries, or stops a task through the same application actions as the CLI and web UI. Start and retry use `src/Actions/QueueTask.php` and require a running host queue worker. See [Laravel MCP local servers](https://laravel.com/framework/docs/13.x/mcp).

## Ask Jev to review a plan or commit

TypeSafe AI is optional and disabled by default. To enable Jev, set `molly.typesafe.enabled` to `true` in the published configuration and supply `TYPESAFE_API_KEY` through the host environment. Never commit the key. No JavaScript bridge or TypeSafe SDK is installed. `src/Actions/EvaluateWithTypeSafe.php` uses the [documented TypeSafe HTTP API](https://docs.typesafe.ai/api).

During planning, request a Jev suggestion in the web interface or through the MCP plan tool. Jev selects one of the five review areas. Molly supplies the corresponding question and citations from the graph, then saves the confidence and the answers used for evaluation. A suggestion does not advance the review or overwrite your decisions. The ordinary guide remains usable without Jev.

Review a commit or staged PHP changes:

```bash
php artisan molly:review-commit HEAD
php artisan molly:review-commit --staged --json
```

The command reads PHP changes, excludes vendor and environment files, runs `git diff --check`, and optionally sends the bounded diff with cited Tarpit/Laravel guidance to Jev. Merge commits use [Git's first-parent comparison](https://git-scm.com/docs/git-show#Documentation/git-show.txt-first-parent) so the review includes the changes the merge introduced. The command does not run tests, change files, or create a commit. The report names its scope and diff digest. `continue` means Jev identified no semantic blocker; it does not prove that the code works. `retry`, `stop`, and `needs_review` require attention and produce a nonzero exit. Disabled or empty evaluations stay explicitly unevaluated. A Git whitespace failure also produces a nonzero exit.

Enabling TypeSafe permits sending planning descriptions, answers, selected source passages, or the explicitly requested commit diff to the hosted service. The complete evaluation input is limited to 32 KiB; commit diffs are limited to 16 KiB. Oversized input is rejected before sending, with no silent truncation. Low confidence, missing answers, malformed results, timeouts, and provider errors produce `needs_review`. Jev cannot bypass tests, Tarpit blockers, or attempt limits. Automatic loop execution from these suggestions is not implemented.

## Distribution size

Install Molly with `composer require --dev`. Production installs using `composer install --no-dev` omit Molly. The source graph and citations must remain below 128 KiB uncompressed. Distribution archives exclude tests and repository automation through `.gitattributes`; they retain the runtime guide, attribution, and licenses. These limits describe Molly's files, not the size of Composer dependencies already installed by the host.

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

The retry writer receives the latest attempt's test status, counts, reason, a bounded output excerpt, up to three review findings for allowed files with blocking findings first, and a bounded run error. `src/Actions/StartTask.php` selects these fields and keeps the encoded diagnostic input below 8192 bytes. `src/Actions/GenerateChanges.php` sends the diagnostics separately as `previous_attempt`. The writer treats diagnostic text as untrusted data to diagnose, preserves the original task and file scope, and must not weaken assertions to hide a failure. The retry does not send the full prior report, provider configuration, or source snapshots. First attempts and stopped tasks without a prior run omit these diagnostics.

Stop ends a pending task immediately. For an active run, stop saves a request that execution checks between stages and after generation, before applying the proposal. A stop request terminates active parallel check processes, including Pest child processes. Stop does not interrupt generation. With serial checks, stop waits for an active review or Pest process to finish. Applied edits remain available for review.

If a process exited before saving its final status, `molly:stop` can settle the interrupted task and running attempt once the task, workspace, and active-check locks are free. Saved evidence remains available. A busy or invalid lock never counts as proof that a process stopped. These locks require runners to share the local filesystem. You can then retry within the attempt limit.

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

## Use the local web interface

Molly includes free Flux 2 and Livewire 4 dependencies. Composer installs both with the package. The interface uses Flux buttons and package CSS, so the host application does not need Flux Pro, a license key, or a Vite build.

The interface is disabled by default. Add these settings to the host application's `.env`:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
QUEUE_CONNECTION=database
```

The database queue needs Laravel's jobs and failed-jobs tables. Keep the host application's queue migrations and run `php artisan migrate` before starting a worker. In the host application's `config/queue.php`, set the database connection's `retry_after` to `3700` seconds. The reservation must exceed the job's 3600-second timeout, or another worker could reserve the same request while execution is still active.

For multiple workers sharing SQLite, the tested configuration requires PHP 8.4 or later. Set these keys on the SQLite connection in the host application's `config/database.php`:

```php
'transaction_mode' => 'IMMEDIATE',
'busy_timeout' => 10000,
```

`busy_timeout` is in milliseconds. With SQLite's default `DEFERRED` transactions, concurrent workers can fail while reserving a job with `database is locked`, before Molly starts the task. Laravel applies `transaction_mode` only on PHP 8.4 or later. Use one SQLite worker on PHP 8.3. Molly does not change the host's database settings.

Clear cached configuration, then start a worker:

```bash
php artisan config:clear
php artisan queue:work --tries=1 --timeout=3600
```

In a second terminal, start the local web server:

```bash
php artisan serve --host=127.0.0.1
```

Open `/molly` on that server. The task list shows the latest 100 saved tasks. Choose **Create task** to enter a prompt or import a GitHub issue, select the workspace and allowed files, and name the required Pest test. Saving a task does not execute code. The task page provides start, retry, and stop controls with links to recorded attempts.

Start and retry queue a request through the host application's default connection. `src/Jobs/StartSavedTask.php` calls the same application actions as the CLI. The worker checks task state and attempt limits when the request executes. Queuing a request does not mean a run has started. Duplicate requests cannot execute the same task concurrently. Each job has one attempt; Molly does not automatically retry failed tasks. If no attempt appears, inspect the worker output and `php artisan queue:failed` before submitting another request.

Database, Redis, Beanstalkd, and SQS connections are supported. Database, Redis, and Beanstalkd require `retry_after` above 3600 seconds. For SQS, configure the queue's visibility timeout above 3600 seconds in AWS. Molly cannot inspect that SQS setting. The web interface rejects synchronous, deferred, null, and failover connections so task execution cannot block the HTTP request. CLI execution remains available without a queue worker.

Every core page renders complete HTML. Create, import, start, retry, and stop use CSRF-protected forms and redirects and work without JavaScript. Livewire refreshes the run status when JavaScript is available. Use **Refresh task** or **Refresh all evidence** to read the latest saved attempts and report. Run pages show Pest evidence, all seven Tarpit checks, unresolved findings, separate Clever measurements and their limitations, and branch outcomes when the report records them. Missing or skipped evidence never appears as a passing check.

The interface only accepts `local` or `testing` environments, direct connections from `127.0.0.1` or `::1`, and a `localhost`, `127.0.0.1`, or `[::1]` host. Custom development domains and remote clients are rejected. The same guard runs on Livewire status requests. The interface has no login or approval controls and is intended for a trusted local machine. Do not expose the interface through a reverse proxy or tunnel.

## Read the result

Molly measures complexity before editing. The writer receives the task, the selected files, and the required test path. Molly validates the proposed paths and applies the edits. By default, Pest verification and Tarpit review then run concurrently in separate local processes. The reviewer receives the saved before-and-after file contents. Molly joins both results and measures complexity again.

Both branches must return passing evidence before the run can complete. Reports retain each branch's ID, attempt ID, local execution target, provider and model when applicable, start and finish times, status, failure classification, and result file. A process finishing does not mean its check passed. `molly.parallel_checks=false` selects serial execution explicitly.

Parallel branch deadlines use `molly.test_timeout` for Pest and `molly.timeout` for review. Molly bounds each deadline to 1 through 3600 seconds, then adds 10 seconds for process startup and cleanup. `src/Actions/EvaluateChanges.php` manages the child processes; the internal `molly:check` command in `src/Console/MollyCheckCommand.php` runs one check and writes its result. Child processes do not update task or run records.

Active check processes hold shared locks on `.molly/checks.lock`. The `.molly/checks.lease` file identifies the owning run. These files prevent a retry from overlapping checks left active after the parent process exits. Delayed children from an earlier lease fail before running checks. `src/Workspace.php` owns this locking behavior. Input JSON files use `0600` permissions and are removed after the branches join. Result files remain with the run evidence.

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
| `molly.agent` | `ollama` | Choose `ollama` or `amp` using `MOLLY_AGENT`. `src/Actions/ConfigureAgent.php` persists onboarding choices. Generation, review, doctor, and parallel workers consume the selected provider. |
| `molly.model` | Required | Local Ollama model name from `MOLLY_LOCAL_MODEL`. `src/Agents/LocalOllama.php` validates the choice; `src/Actions/GenerateChanges.php` and `src/Actions/ReviewChanges.php` use the model. |
| `molly.timeout` | `180` | Positive integer timeout in seconds for each model request. The writer and reviewer consume this value. |
| `molly.test_timeout` | `120` | Pest process timeout in seconds, bounded to 1 through 3600 by `src/Actions/VerifyChanges.php`. |
| `molly.parallel_checks` | `true` | Run Pest verification and Tarpit review concurrently through `src/Actions/EvaluateChanges.php`. Requires `posix_setsid` and `posix_kill`. Set to `false` for serial checks in `src/Actions/RunTask.php`. No environment variable is assigned. |
| `molly.max_attempts` | `3` | Maximum runs per saved task, including the first attempt. Accepts integers 1 through 10. `src/Actions/StartTask.php` checks the limit before changing task state. |
| `molly.ui.enabled` | `false` | Opt in to local web routes with `MOLLY_UI_ENABLED=true`. `src/Http/LocalUi.php` also requires a local or testing environment and a loopback client and host. The guard also checks Livewire status requests. |
| `molly.ui.prefix` | `molly` | URL prefix consumed by `routes/web.php`. The default task list is `/molly`. Edit the published configuration to change the prefix; no environment variable is assigned. |
| `molly.max_files` | `8` | Maximum selected file count, checked by `src/Workspace.php`. |
| `molly.max_file_bytes` | `65536` | Maximum bytes in each selected file and proposed replacement, checked by `src/Workspace.php`. |
| `ai.providers.ollama.driver` | `ollama` | Laravel AI provider driver. Molly requires `ollama`. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Local Ollama endpoint from `OLLAMA_URL`. Molly permits HTTP loopback addresses without credentials, a query, or an extra path. |

Laravel AI owns the Ollama provider settings. `MOLLY_LOCAL_MODEL` selects the model. `MOLLY_COMPLEXITY_ENABLED` controls bundled measurements outside production. `MOLLY_UI_ENABLED` opts in to the local web interface. Web and MCP dispatchers read the host queue connection through `queue.default` and its driver and `retry_after` settings in `src/Actions/QueueTask.php`. Laravel maps `QUEUE_CONNECTION` to `queue.default` in the host configuration. Edit the remaining settings in the published files.

| TypeSafe key | Default | Purpose |
| --- | --- | --- |
| `molly.typesafe.enabled` | `false` | Explicitly permit hosted evaluations. No automatic provider fallback. |
| `molly.typesafe.api_key` | `TYPESAFE_API_KEY` | Credential for the official TypeSafe endpoint. Store the key only in the host environment. |
| `molly.typesafe.model` | `jev-latest` | Model identifier sent to TypeSafe. |
| `molly.typesafe.confidence_threshold` | `0.8` | Lower-confidence answers become `needs_review`. Must be between zero and one. |
| `molly.typesafe.timeout` | `30` | HTTP timeout in seconds, from 1 to 120. |
| `molly.typesafe.instructions` | Task-evidence question | Instructions for the public task-evidence evaluator. Planning and commit evaluation use their own bounded questions. |

`src/Actions/EvaluateWithTypeSafe.php` consumes these settings. Only the API key has a corresponding environment variable. Existing published configuration must add the `typesafe` section to opt in. `SuggestPlanReview` and `ReviewCommit` use the same client with distinct rubrics.

| Complexity key | Default | Purpose |
| --- | --- | --- |
| `molly-complexity.enabled` | `null` | Enable in local and testing by default. `MOLLY_COMPLEXITY_ENABLED` may override outside production. Production always disables measurements. |
| `molly-complexity.root` | `null` | Measure the host application unless a workspace root is supplied. |
| `molly-complexity.report.path` | `null` | Write standalone reports to `storage/molly/complexity/report.json`. Task measurements use a unique path beneath the run's evidence directory. |
| `molly-complexity.probes` | Four bundled probe classes | Select measurements for task runs and `clever:scan`. Individual commands run their named probe. An empty set cannot complete a task. |
| `molly-complexity.owned_diff.paths` | `app`, `bootstrap`, `config`, `database`, `routes`, `resources/js` | Select directories for owned-code counts and hotspots. Lonely-files uses PHP files found in Git history. |
| `molly-complexity.owned_diff.extensions` | `php`, `js`, `ts`, `jsx`, `tsx`, `vue`, `css`, `json` | Select source extensions for owned-code counts. |
| `molly-complexity.welds.paths` | `app` | Select PHP directories for construction and static-call counts. |
| `molly-complexity.welds.facades` | `[]` | Add facade names to the reported facade classification. |
| `molly-complexity.welds.max_sites` | `200` | Limit reported call-site details. |
| `molly-complexity.lonely.min_lines` | `30` | Exclude smaller files from the lonely-files list. |
| `molly-complexity.lonely.limit` | `10` | Limit listed single-author files. |
| `molly-complexity.churn.since` | `24 months ago` | Select the Git history window for hotspots. Lonely-files uses all available history. |
| `molly-complexity.churn.limit` | `20` | Limit hotspot entries. |
| `molly-complexity.exclude` | `[]` | Exclude additional directories from source enumeration. |

`src/Complexity/Support/CleverConfig.php` consumes these settings. During a task scan, `src/Actions/MeasureComplexity.php` selects the workspace and evidence path, then restores the host settings. Standalone `clever:*` commands use the configured host root. No separate Clever package or Clever configuration file is required.

If an application previously installed `maryperry/clever` only for Molly, remove that package after updating Molly to avoid duplicate `clever:*` commands. Move supported custom `clever.*` settings to the corresponding `molly-complexity.*` keys. Keep Molly's default probe list, or port custom probes to `Sifrious\Molly\Complexity\Probes\Probe`. Old `Clever\Clever\Probes\...` classes require the removed package. `clever.route.*` settings have no bundled equivalent.

## Limits of the demo

Use a trusted, disposable checkout. The file allowlist confines writer proposals. Pest executes PHP with the current user's permissions, so the allowlist is not a process sandbox. The selected test file is writable by the model. Review the resulting assertions and diff before accepting the change.

Failed verification or review leaves applied edits in place for inspection. Molly does not commit changes. An interrupted process can leave a run marked `running`; that state is uncertain and does not mean success. Saved tasks support explicit interruption settlement through `molly:stop`. One-off runs have no task controls. Automatic resume is not implemented.

Arbitrary task dependency graphs, automatic task decomposition or bulk import, Bloom integration, GitHub-to-Pest todo generation, approval controls, and remote execution remain outside this build. The bundled source graph guides planning; it is not a task scheduler. TypeSafe suggestions and commit evaluation are available, but do not yet customize or automatically execute a general loop.

## Terms

| Term | Meaning | Implementation |
| --- | --- | --- |
| Plan | A description for a collection of tasks, an explicit guided-or-skipped choice, and saved planning decisions | `src/Models/Plan.php`, `src/Actions/AnswerPlan.php` |
| Planning suggestion | Jev's optional review focus with confidence, cited guidance, and an answer snapshot | `src/Actions/SuggestPlanReview.php` |
| Source graph | Bundled questions and source passages linked by stable IDs, revisions, and digests | `src/PlanningGuide.php`, `resources/planning/guide.json` |
| Task | A saved prompt, selected files, source context, and lifecycle state | `src/Models/Task.php`, `src/Actions/CreateTask.php` |
| Run | A persisted attempt with `running`, `completed`, `failed`, or `stopped` status and a report | `src/Models/Run.php` |
| Queued execution request | A task ID and start-or-retry choice waiting for a host queue worker; a request does not count as a run until execution begins | `src/Jobs/StartSavedTask.php`, `src/Actions/QueueTask.php` |
| Execution branch | One local Pest verification or Tarpit review process with its own result and failure metadata; a branch result is separate from run completion | `src/Actions/EvaluateChanges.php`, `src/Console/MollyCheckCommand.php` |
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

`.github/workflows/tests.yml` runs Composer validation and the package tests on Ubuntu with PHP 8.3, 8.4, and 8.5. Each job resolves dependencies for its PHP version because the package does not commit `composer.lock`. CI installs DOM, SQLite, PCNTL, and POSIX extensions for the verification and process tests. The suite uses model and process fakes where needed and does not require Ollama or an externally managed queue worker. `tests/Feature/QueuedExecutionTest.php` starts isolated database workers with file-backed SQLite, real Pest subprocesses, and the parallel check engine. Only model responses are faked in those integration cases. The competing SQLite workers case skips on PHP 8.3 because Laravel does not apply `transaction_mode` on that version. The single-worker failure and stop cases still run.

The package tests use Pest and Orchestra Testbench. Laravel AI fakes test model responses without requiring Ollama. A live demo requires an installed local model and a host application. Molly supplies the complexity commands.

The first live check used PHP 8.4.23, Laravel 13.32.0, Laravel AI 0.11.2, and the local `gpt-oss:120b-code` model. Molly updated a named health route and its Pest test, then completed the workflow in 25 seconds. Pest passed one test with three assertions. The review returned all seven Tarpit checks without findings, and all four Clever probes returned results before and after the edit. This verifies a small CLI task. It does not establish reliability across larger tasks or other models.

The saved-task workflow also passed a live run after removing the separate Clever package. Molly created and started a task, saved its completed run, and verified a readiness endpoint with one Pest test and three assertions. A real read-only GitHub import preserved issue identity and created a pending task. That import check did not execute the issue.

The local web workflow passed a live create, queue, and retry check with the same model. The first attempt added a version endpoint but failed Pest because the generated test omitted an import. Molly kept the run failed despite a passing Tarpit review. The retry received the recorded test error, added the import, and completed in 30 seconds with one test and two assertions. Pest and review ran in separate overlapping processes. Both attempts remain in task history. Running reports also save the current execution phase before model calls and check transitions.

## Source credit

The bundled measurements derive from Clever commit `650a32a595036ef610a7c7af1fad4869b23ef05a` in [sifrious/cleverness](https://github.com/sifrious/cleverness). Molly preserves the original MIT notice and source reference in `src/Complexity/LICENSE.md`. Molly does not copy Clever's web interface or require the original package.

The Amp path passed a live bounded coding check in the demo application: Amp proposed a PHP file and its Pest test, Molly applied the allowed files, Pest passed one test with one assertion, and a parallel Amp Tarpit review passed. The run records provider `amp` and model `null`. Amp's MCP doctor also connected to all three Molly tools. These checks do not establish remote Orb execution or a live TypeSafe result. TypeSafe behavior is covered with documented response fixtures until a key is configured.

---
layout: default
title: Configuration reference
---

# Configuration reference

Publish both Molly configuration files in the host Laravel application:

```bash
php artisan vendor:publish --tag=molly-config
```

The tag copies `config/molly.php` and `config/molly-complexity.php`. Edit those published files for settings without an environment variable. After changing configuration or `.env`, clear Laravel's cached configuration and check readiness:

```bash
php artisan config:clear
php artisan molly:doctor
```

Restart an existing queue worker after configuration changes so the next web task uses the new settings. See the [web interface guide](../web-interface.md) for queue setup.

## Task settings

| Key | Default | Meaning and consumer |
| --- | --- | --- |
| `molly.agent` | `ollama` | Chooses `ollama` or `amp` through `MOLLY_AGENT`. `src/Actions/GenerateChanges.php` and `src/Actions/ReviewChanges.php` dispatch to the chosen provider; `src/Actions/CheckEnvironment.php` checks its setup. Unknown values fail without fallback. |
| `molly.model` | `null` | Required when `molly.agent` is `ollama`. Exact installed local model name from `MOLLY_LOCAL_MODEL`. `src/Agents/LocalOllama.php` validates the setting; the writer and reviewer actions use the model. Amp does not use this setting. |
| `molly.timeout` | `180` | Seconds for each proposal or review request. Ollama requires a positive integer; `src/Agents/AmpResponse.php` accepts integers from 1 through 3600. Parallel review deadlines clamp the value to that range and add 10 seconds for startup and cleanup. |
| `molly.test_timeout` | `120` | Pest timeout in seconds. `src/Actions/VerifyChanges.php` bounds the value to 1 through 3600. Parallel verification adds 10 seconds around that bound for startup and cleanup. |
| `molly.parallel_checks` | `true` | Runs Pest and Tarpit review concurrently through `src/Actions/EvaluateChanges.php`. Requires `posix_setsid` and `posix_kill`. Set the boolean to `false` for serial execution in `src/Actions/RunTask.php`. No environment variable is assigned. |
| `molly.max_attempts` | `3` | Maximum runs per saved task, including the first attempt. Must be an integer from 1 through 10. `src/Actions/StartTask.php` checks the limit before changing task state. `src/Actions/RecommendTaskNextStep.php` uses the same limit when reporting retry permission. |
| `molly.max_files` | `8` | Maximum distinct files a task may select, including the required test. `src/Workspace.php` checks the count. |
| `molly.max_file_bytes` | `65536` | Maximum bytes per selected file or proposed replacement. `src/Workspace.php` checks both existing contents and replacements. |
| `molly.ui.enabled` | `false` | Opts in to local web routes through `MOLLY_UI_ENABLED=true`. `src/Http/LocalUi.php` also requires a `local` or `testing` environment, loopback client, and loopback host. The same guard checks Livewire status requests. |
| `molly.ui.prefix` | `molly` | Route prefix consumed by `routes/web.php`. The default task list is `/molly`. No environment variable is assigned. |

The prompt limit is 8,192 bytes. Accepted writer paths remain under `app/`, `routes/`, `resources/`, and `tests/`; changing a size limit does not expand those directories. Molly adds the selected Pest test to the file allowlist. You do not need to list the test again among the other files. See [task scope](../tasks.md#create-a-task).

## Knowledge graph settings

| Key | Default | Meaning and consumer |
| --- | --- | --- |
| `molly.knowledge.database` | `.molly/knowledge.sqlite` | Local PDO SQLite file used by `src/Knowledge/Graph.php`. Set `MOLLY_KNOWLEDGE_DATABASE` to use another local path. Relative paths start at the host application's base path. |

Keep `.molly/` out of version control. The knowledge database is disposable.
Run `php artisan molly:knowledge:index laravel` to replace the installed Laravel
version's snapshot. No hosted graph service is used.

## Ollama provider settings

Laravel AI owns the Ollama provider configuration. Molly uses these settings when `molly.agent` is `ollama`, including when setup lists installed local models.

| Key | Default | Meaning and consumer |
| --- | --- | --- |
| `ai.providers.ollama.driver` | `ollama` | Provider driver. `src/Agents/LocalOllama.php` requires the `ollama` value before generation or review. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Ollama endpoint from `OLLAMA_URL`. `src/Agents/LocalOllama.php` accepts HTTP on `localhost`, `127.0.0.1`, or `[::1]`, with no credentials, query, fragment, or extra path. `src/Actions/CheckEnvironment.php` and `src/Actions/ConfigureAgent.php` request the installed model list. |

Set `MOLLY_LOCAL_MODEL` to the model name shown by `ollama list`. Molly rejects an empty model or a model name containing `cloud`. Molly does not download models or switch providers automatically.

## Amp settings

`molly:setup --agent=amp` saves `MOLLY_AGENT=amp` and asks Amp to add the host application's stdio MCP server to workspace configuration. Amp owns the account credentials and model selection. Molly has no Amp token or Amp model setting.

The `amp` executable must be available on the `PATH` of the process running Molly. `src/Agents/AmpResponse.php` creates temporary settings for proposal and review requests rather than editing the user's Amp settings. `src/Console/MollyChatCommand.php` supplies the Molly MCP connection for an interactive Amp session. See [agent setup](../agents.md).

`molly:setup --agent=ollama --model=NAME` instead saves the local provider and model. `src/Actions/ConfigureAgent.php` preserves unrelated environment values and refuses duplicate keys, invalid syntax, symbolic links, and unsupported layouts. Setup reports `php artisan config:clear` when configuration is cached.

## TypeSafe evaluation settings

TypeSafe is an optional hosted evaluator. Enabling the evaluator permits explicit planning, task advice, and commit review requests to send their selected evidence to TypeSafe. The regular guide and task execution remain usable with the evaluator disabled.

All keys below are consumed by `src/Actions/EvaluateWithTypeSafe.php`. The implementation calls the TypeSafe HTTP API directly; it does not use a native TypeSafe classification API from Laravel AI `0.11.2`.

| Key | Default | Meaning |
| --- | --- | --- |
| `molly.typesafe.enabled` | `false` | Boolean opt-in. `false` returns an explicitly disabled evaluation without a network request. Only boolean `true` enables requests. |
| `molly.typesafe.api_key` | `null` | Credential read from `TYPESAFE_API_KEY`. Required when enabled. The key must be nonempty and contain no carriage return or newline. Supply the key through the host environment, not committed configuration. |
| `molly.typesafe.model` | `jev-latest` | Requested model. Must be a nonempty string of at most 128 bytes. Reports retain the returned provider model when available. |
| `molly.typesafe.confidence_threshold` | `0.8` | Finite number from `0` through `1`. A lower-confidence answer remains `needs_review`. |
| `molly.typesafe.timeout` | `30` | Integer request timeout from 1 through 120 seconds. Connection timeout is the smaller of this value and 10 seconds. Redirects are disabled. |
| `molly.typesafe.instructions` | `Which next action does this task and its test and review evidence support?` | Instructions for task next-action evaluation. Must contain 1 through 4096 bytes. Planning and commit review use their own fixed questions. |

The endpoint is fixed at `https://api.typesafe.ai/v1/systemone`. There is no endpoint environment override. Encoded evidence has a 32 KiB limit. Invalid configuration, unsupported answers, incomplete evidence, low confidence, and provider failures produce explicit reasons rather than silently approving work.

Planning suggestions pass the description, saved answers, guide version, and selected excerpts through `src/Actions/SuggestPlanReview.php`. Commit review passes a PHP diff and cited guidance through `src/Actions/ReviewCommit.php`; the diff has a separate 16 KiB limit. `src/Actions/RecommendTaskNextStep.php` can evaluate bounded failed-attempt evidence when a retry remains permitted. See [task advice](../task-advice.md). These evaluations do not bypass required tests, Tarpit blockers, or attempt limits.

## Complexity settings

`src/Complexity/Support/CleverConfig.php` reads the `molly-complexity.*` settings. The default source is `config/molly-complexity.php`.

| Key | Default | Meaning and consumer |
| --- | --- | --- |
| `molly-complexity.enabled` | `null` | `src/Complexity/Clever.php` enables measurements in `local` and `testing` unless explicitly disabled. Other non-production environments require `true`. Production always disables measurements. `MOLLY_COMPLEXITY_ENABLED` maps to this key. |
| `molly-complexity.root` | `null` | Standalone measurement root. A null or empty value uses the host application's base path. Probe file enumeration and Git commands use the root. |
| `molly-complexity.report.path` | `null` | Standalone report destination. A null or empty value uses `storage/molly/complexity/report.json`. `src/Complexity/Report/ReportRepository.php` reads the path and `src/Complexity/Report/ReportWriter.php` writes the report. |
| `molly-complexity.probes` | Four bundled probe classes | Selects measurements for task scans and `clever:scan`. `src/Complexity/Clever.php` resolves each class and requires the `Probe` contract. Individual commands run their named probe regardless of this list. An empty list cannot complete a task. |
| `molly-complexity.owned_diff.paths` | `app`, `bootstrap`, `config`, `database`, `routes`, `resources/js` | Directories or files for owned-code counts and the path filter for hotspots. Consumed by `src/Complexity/Probes/OwnedDiffProbe.php` and `src/Complexity/Probes/HotspotsProbe.php`. Lonely-files scans PHP history independently. |
| `molly-complexity.owned_diff.extensions` | `php`, `js`, `ts`, `jsx`, `tsx`, `vue`, `css`, `json` | Source extensions counted by `src/Complexity/Probes/OwnedDiffProbe.php`. Selecting `php` also includes Blade PHP files within the configured paths. |
| `molly-complexity.welds.paths` | `app` | PHP directories or files scanned by `src/Complexity/Probes/WeldedCallSitesProbe.php`. |
| `molly-complexity.welds.facades` | `[]` | Additional facade names recognized by `src/Complexity/Support/WeldScanner.php`. Facade calls remain in the static total and receive a separate count. |
| `molly-complexity.welds.max_sites` | `200` | Maximum details retained for each constructor and static-call list. Counts still include matched sites beyond the limit. Consumed by `src/Complexity/Probes/WeldedCallSitesProbe.php`. |
| `molly-complexity.lonely.min_lines` | `30` | Minimum current code lines for a listed single-author file in `src/Complexity/Probes/LonelyFilesProbe.php`. |
| `molly-complexity.lonely.limit` | `10` | Maximum entries in the lonely-files list. Consumed by `src/Complexity/Probes/LonelyFilesProbe.php`. |
| `molly-complexity.churn.since` | `24 months ago` | History window passed to Git by `src/Complexity/Probes/HotspotsProbe.php`. An empty or non-string value omits the date filter. Lonely-files uses all available history. |
| `molly-complexity.churn.limit` | `20` | Maximum hotspot entries. Consumed by `src/Complexity/Probes/HotspotsProbe.php`. |
| `molly-complexity.exclude` | `[]` | Additional directory names skipped during recursive source enumeration in `src/Complexity/Support/SourceFiles.php`. Does not change Git history selection. |

The default probe classes share the `Sifrious\Molly\Complexity\Probes` namespace:

```php
OwnedDiffProbe::class,
WeldedCallSitesProbe::class,
LonelyFilesProbe::class,
HotspotsProbe::class,
```

Use the complete class names or import the classes when editing the configuration. `CleverConfig` filters list settings to strings and uses fallback values for invalid scalar types. Positive numeric limits must be positive integers. Prefer the shipped types instead of relying on fallback behavior.

During task scans, `src/Actions/MeasureComplexity.php` overrides the measurement root with the task workspace and writes a unique report under `storage/molly/{run-id}`. The action restores the host's root and report setting afterward. Standalone `clever:*` commands use the configured host root and report destination.

Recursive enumeration skips `vendor`, `node_modules`, `storage`, hidden directories, and symbolic links. The default owned paths omit `tests` and `resources/views`. Missing paths and unavailable Git history remain visible in the results. Read [measurement limitations](../verification.md#clever-measurements) before comparing counts.

## Host application settings

Molly uses the host application's existing database, environment, and queue configuration. Molly does not define separate database credentials.

| Setting | Meaning and consumer |
| --- | --- |
| Application environment | `src/Complexity/Clever.php` controls measurement availability and `src/Http/LocalUi.php` limits the UI to `local` or `testing`. `src/MollyServiceProvider.php` registers the local MCP server only in those environments. Laravel normally reads the environment from `APP_ENV`. |
| `app.name` | Name recorded in standalone reports by `src/Complexity/Report/ReportWriter.php` through `CleverConfig`. Invalid or empty values fall back to `Laravel`. The standard host configuration reads `APP_NAME`. |
| `database.default` and its connection | Host database used by `src/Models/Task.php`, `src/Models/Run.php`, `src/Models/Plan.php`, and task-thread association actions. Run the supplied migrations against that database. Host database environment variables remain defined by the host's `config/database.php`. |
| `queue.default` | Queue connection used by web and MCP start and retry requests. `src/Actions/QueueTask.php` reads the connection and Laravel normally maps `QUEUE_CONNECTION` to this key. |
| `queue.connections.{connection}.driver` | Queued execution accepts `database`, `redis`, `beanstalkd`, or `sqs`. `src/Actions/QueueTask.php` rejects synchronous, deferred, null, and failover drivers. |
| `queue.connections.{connection}.retry_after` | Database, Redis, and Beanstalkd reservations must exceed the job's 3600-second timeout. `src/Actions/QueueTask.php` checks this setting. A value of `3700` is suitable. |
| SQS visibility timeout | Set above 3600 seconds on the SQS queue. Molly cannot inspect this AWS setting. |

`src/Jobs/StartSavedTask.php` has one queue attempt and a 3600-second timeout. Queueing a request does not create a run until execution begins. CLI tasks execute in the terminal and do not require a queue worker.

## Environment variables

These names describe settings, not secret values.

| Variable | Meaning | Related key |
| --- | --- | --- |
| `MOLLY_AGENT` | Explicit provider choice, `ollama` or `amp`. Defaults to `ollama`. Setup writes the selected value. | `molly.agent` |
| `MOLLY_LOCAL_MODEL` | Installed local model used for writing and review when the agent is `ollama`. Not required for Amp. | `molly.model` |
| `TYPESAFE_API_KEY` | Secret credential for explicitly enabled TypeSafe evaluations. No key value belongs in documentation or committed configuration. | `molly.typesafe.api_key` |
| `MOLLY_UI_ENABLED` | Boolean opt-in to the guarded local UI. Defaults to `false`. | `molly.ui.enabled` |
| `MOLLY_COMPLEXITY_ENABLED` | Optional boolean measurement override outside production. Unset uses the environment defaults. | `molly-complexity.enabled` |
| `OLLAMA_URL` | Local HTTP endpoint for Ollama. Laravel AI defaults to `http://localhost:11434`. | `ai.providers.ollama.url` |
| `APP_ENV` | Host environment, normally `local` during Molly development. Controls UI access, MCP registration, and measurement availability. | Laravel application environment |
| `APP_NAME` | Host application name included in measurement reports when mapped by the host configuration. | `app.name` |
| `QUEUE_CONNECTION` | Host queue connection for web and MCP start and retry requests. | `queue.default` |

Molly does not assign environment variables to the timeout, size, attempt, parallel-execution, or route-prefix settings. Edit the published PHP configuration for those values. Some Laravel hosts map `DB_QUEUE_RETRY_AFTER` to the database queue reservation; inspect the host's `config/queue.php` before relying on that variable. Setting an unmapped variable has no effect.

## Move from a separate Clever installation

If the host installed `maryperry/clever` only for Molly, update Molly and remove the separate package to avoid duplicate `clever:*` commands:

```bash
composer update sifrious/molly --with-dependencies
composer remove --dev maryperry/clever
```

The removal command assumes the old package is in `require-dev`. If the host lists the old package under `require`, omit `--dev`.

Move supported custom `clever.*` values into the corresponding `molly-complexity.*` keys in `config/molly-complexity.php`. Keep Molly's default probe list, or port custom probes to `Sifrious\Molly\Complexity\Probes\Probe`. The old `Clever\Clever\Probes\...` classes belong to the removed package. `clever.route.*` has no bundled equivalent because Molly does not include Clever's web interface.

After the migration, clear cached configuration and check the commands:

```bash
php artisan config:clear
php artisan molly:doctor
php artisan clever:scan --json
```

Molly needs no separate Clever configuration file or package. The bundled source preserves the original MIT notice in `src/Complexity/LICENSE.md`.

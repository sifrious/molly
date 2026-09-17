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
| `molly.model` | Required | Exact installed local Ollama model name from `MOLLY_LOCAL_MODEL`. `src/Agents/LocalOllama.php` validates the setting; `src/Actions/GenerateChanges.php` and `src/Actions/ReviewChanges.php` select the model. |
| `molly.timeout` | `180` | Positive integer seconds for each model request. Consumed by the writer and reviewer actions. Parallel review deadlines clamp the value to 1 through 3600 seconds and add 10 seconds for startup and cleanup. |
| `molly.test_timeout` | `120` | Pest timeout in seconds. `src/Actions/VerifyChanges.php` bounds the value to 1 through 3600. Parallel verification adds 10 seconds around that bound for startup and cleanup. |
| `molly.parallel_checks` | `true` | Runs Pest and Tarpit review concurrently through `src/Actions/EvaluateChanges.php`. Requires `posix_setsid` and `posix_kill`. Set the boolean to `false` for serial execution in `src/Actions/RunTask.php`. No environment variable is assigned. |
| `molly.max_attempts` | `3` | Maximum runs per saved task, including the first attempt. Must be an integer from 1 through 10. `src/Actions/StartTask.php` checks the limit before changing task state. |
| `molly.max_files` | `8` | Maximum distinct files a task may select. `src/Workspace.php` checks the count. |
| `molly.max_file_bytes` | `65536` | Maximum bytes per selected file or proposed replacement. `src/Workspace.php` checks both existing contents and replacements. |
| `molly.ui.enabled` | `false` | Opts in to local web routes through `MOLLY_UI_ENABLED=true`. `src/Http/LocalUi.php` also requires a `local` or `testing` environment, loopback client, and loopback host. The same guard checks Livewire status requests. |
| `molly.ui.prefix` | `molly` | Route prefix consumed by `routes/web.php`. The default task list is `/molly`. No environment variable is assigned. |

The prompt limit is 8,192 bytes. Accepted writer paths remain under `app/`, `routes/`, `resources/`, and `tests/`; changing a size limit does not expand those directories. The selected Pest test must also appear in the file allowlist. See [task scope](../tasks.md#create-a-task).

## Ollama provider settings

Laravel AI owns the Ollama provider configuration. Molly uses the `ollama` provider explicitly.

| Key | Default | Meaning and consumer |
| --- | --- | --- |
| `ai.providers.ollama.driver` | `ollama` | Provider driver. `src/Agents/LocalOllama.php` requires the `ollama` value before generation or review. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Ollama endpoint from `OLLAMA_URL`. `src/Agents/LocalOllama.php` accepts HTTP on `localhost`, `127.0.0.1`, or `[::1]`, with no credentials, query, fragment, or extra path. `src/Actions/CheckEnvironment.php` requests the model list from the endpoint. |

Set `MOLLY_LOCAL_MODEL` to the model name shown by `ollama list`. Molly rejects an empty model or a model name containing `cloud`. Molly does not download a model or switch to a paid provider.

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
| Application environment | `src/Complexity/Clever.php` controls measurement availability and `src/Http/LocalUi.php` limits the UI to `local` or `testing`. Laravel normally reads the environment from `APP_ENV`. |
| `app.name` | Name recorded in standalone reports by `src/Complexity/Report/ReportWriter.php` through `CleverConfig`. Invalid or empty values fall back to `Laravel`. The standard host configuration reads `APP_NAME`. |
| `database.default` and its connection | Host database used by `src/Models/Task.php` and `src/Models/Run.php`. Run the supplied migrations against that database. Host database environment variables remain defined by the host's `config/database.php`. |
| `queue.default` | Queue connection used by web start and retry requests. `src/Http/TaskController.php` reads the connection and Laravel normally maps `QUEUE_CONNECTION` to this key. |
| `queue.connections.{connection}.driver` | Web execution accepts `database`, `redis`, `beanstalkd`, or `sqs`. `src/Http/TaskController.php` rejects synchronous, deferred, null, and failover drivers. |
| `queue.connections.{connection}.retry_after` | Database, Redis, and Beanstalkd reservations must exceed the job's 3600-second timeout. `src/Http/TaskController.php` checks this setting. A value of `3700` is suitable. |
| SQS visibility timeout | Set above 3600 seconds on the SQS queue. Molly cannot inspect this AWS setting. |

`src/Jobs/StartSavedTask.php` has one queue attempt and a 3600-second timeout. Queueing a request does not create a run until execution begins. CLI tasks execute in the terminal and do not require a queue worker.

## Environment variables

These names describe settings, not secret values.

| Variable | Meaning | Related key |
| --- | --- | --- |
| `MOLLY_LOCAL_MODEL` | Installed local model used for writing and review. Required for execution. | `molly.model` |
| `MOLLY_UI_ENABLED` | Boolean opt-in to the guarded local UI. Defaults to `false`. | `molly.ui.enabled` |
| `MOLLY_COMPLEXITY_ENABLED` | Optional boolean measurement override outside production. Unset uses the environment defaults. | `molly-complexity.enabled` |
| `OLLAMA_URL` | Local HTTP endpoint for Ollama. Laravel AI defaults to `http://localhost:11434`. | `ai.providers.ollama.url` |
| `APP_ENV` | Host environment, normally `local` during Molly development. Controls UI access and measurement availability. | Laravel application environment |
| `APP_NAME` | Host application name included in measurement reports when mapped by the host configuration. | `app.name` |
| `QUEUE_CONNECTION` | Host queue connection for web start and retry requests. | `queue.default` |

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

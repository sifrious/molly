---
layout: default
title: Command reference
---

# Command reference

Run Molly commands through Artisan in the host Laravel application. Use `php artisan COMMAND --help` for the installed command's help text. Molly's interactive questions and terminal reports use Laravel Prompts.

For a complete example, follow [getting started](../getting-started.md). The [task guide](../tasks.md) explains when to create, retry, and stop saved work.

## Scope and output options

`molly:run`, `molly:create`, and `molly:import` share these options:

| Option | Meaning |
| --- | --- |
| `--workspace=PATH` | Existing checkout to edit. Defaults to the host application's base path. |
| `--file=PATH` | One workspace-relative file Molly may change. Repeat for every selected file. Required. |
| `--test=PATH` | Required Pest PHP test file under `tests/`. Must also appear in `--file`. |
| `--json` | Print the command's JSON result instead of a terminal report. |

Paths under `app/`, `routes/`, `resources/`, and `tests/` are accepted. Hidden segments, traversal, symbolic links, directories, and special files are rejected. See [task scope](../tasks.md#create-a-task) for size and count limits.

Every public Molly command accepts `--json`. Commands that render run reports also honor Artisan's `--verbose` option for full measurement details and branch metadata. Use `--no-interaction` in scripts. A prompt is required for `molly:run` and `molly:create` when `--json` or `--no-interaction` is present.

## Molly commands

The usage below shows Molly-specific arguments and options. Normal Artisan options such as `--help`, `--verbose`, and `--no-interaction` remain available.

| Command | Usage | Result |
| --- | --- | --- |
| Run once | `php artisan molly:run [PROMPT] --file=PATH --test=PATH [--workspace=PATH] [--json]` | Executes a one-off run and saves its report. |
| Create task | `php artisan molly:create [PROMPT] --file=PATH --test=PATH [--workspace=PATH] [--json]` | Saves a pending task without calling the model or editing selected files. |
| Import issue | `php artisan molly:import ISSUE_URL --file=PATH --test=PATH [--workspace=PATH] [--json]` | Reads a GitHub issue through `gh api` and saves a pending task. |
| List tasks | `php artisan molly:tasks [--limit=20] [--json]` | Lists the newest saved tasks first. The limit must be an integer from 1 through 100. |
| Read task | `php artisan molly:task TASK_ID [--json]` | Reads a saved task and its attempts, ordered oldest first. |
| Start task | `php artisan molly:start TASK_ID [--json]` | Starts a pending task in the current process. |
| Retry task | `php artisan molly:retry TASK_ID [--json]` | Creates a new attempt for a failed or stopped task within its attempt limit. |
| Stop task | `php artisan molly:stop TASK_ID [--json]` | Stops a pending task or requests an active task to stop. Can settle an interrupted task after the locks are free. |
| Read run | `php artisan molly:show RUN_ID [--json]` | Reads a saved run report without executing the model again. |
| Check setup | `php artisan molly:doctor [--workspace=PATH] [--json]` | Checks database history, workspace Pest, provider settings, Ollama, installed model, measurements, and POSIX support when parallel checks are enabled. |

`molly:import` requires an HTTPS `github.com` issue URL without a query or fragment. Import cannot execute a pull request or write back to GitHub. See [GitHub import](../tasks.md#import-a-github-issue) for size limits and saved source fields.

Doctor checks readiness for task execution. Doctor does not run the model, execute the required tests, or check the web queue reservation settings.

The internal `molly:check` command belongs to the parallel executor. Use the public task commands to run work. The internal input and output arguments are not a user-facing API.

## JSON results and exit codes

The public Molly commands return exit code `0` on the success conditions below and `1` for a handled command failure. Artisan can report its own error for invalid command syntax before Molly's handler runs.

| Command | Successful JSON fields | Exit code `0` means |
| --- | --- | --- |
| `molly:run` | `id`, `status`, `report` | The new run completed. |
| `molly:create`, `molly:import` | `id`, `status`, `task` | Molly saved a pending task. |
| `molly:tasks` | `tasks` | Molly read the list, including an empty list. |
| `molly:task` | `id`, `status`, `task` | Molly found the task, regardless of its saved status. |
| `molly:start`, `molly:retry` | `id`, `task_id`, `status`, `report` | The new run completed. |
| `molly:stop` | `id`, `status`, `task` | Molly processed the stop request or read an already finished task. An active task may still be stopping. |
| `molly:show` | `id`, `status`, `report` | Molly found the run, regardless of its saved status. |
| `molly:doctor` | `ready`, `checks` | Every readiness check passed. |

The `task` object includes the saved prompt, workspace, selected `paths`, `test_path`, status, source metadata, and timestamps. `molly:task` also includes the `runs` relationship. A run report's available fields depend on how far execution progressed. Read the `status` and failure details before assuming a report contains verification or review evidence.

For example, the following command emits one JSON object and exits nonzero if the new run fails:

```bash
php artisan molly:run \
  'Add GET /health returning JSON {"status":"ok"} and test the exact response.' \
  --file=routes/web.php \
  --file=tests/Feature/HealthTest.php \
  --test=tests/Feature/HealthTest.php \
  --json --no-interaction
```

Read a failed run without changing its status:

```bash
php artisan molly:show RUN_ID --json
```

A successful lookup exits zero even if the returned `status` is `failed`. Check the returned status in scripts that decide whether to accept changes.

Handled errors preserve these command-specific structures:

| Command | Error result |
| --- | --- |
| `molly:run` | `id: null`, `status: "failed"`, and `report.error` when no run is returned. |
| `molly:create`, `molly:import` | `id: null`, `status: "error"`, and `error`. |
| `molly:task`, `molly:stop` | Requested `id`, `status: "error"`, and `error`. |
| `molly:start`, `molly:retry` | `id: null`, requested `task_id`, `status: "error"`, and `report.error`. |
| `molly:show` | Requested `id`, `status: "error"`, and `report.error`. |
| `molly:tasks` | An empty `tasks` array and `error`. |
| `molly:doctor` | `ready: false` and a `checks` list with failed statuses, codes, and details. |

The command handlers live under `src/Console/`. Task execution and state changes live under `src/Actions/`.

## Clever commands

Molly ships all five commands. No separate Clever package is required. Every command accepts `--json` and uses the configured measurement root, which defaults to the host application. There is no `--workspace` option on `clever:*`; set `molly-complexity.root` for standalone scans of another checkout.

| Usage | Measurement | Report behavior |
| --- | --- | --- |
| `php artisan clever:scan [--json]` | All configured probes. | Replaces the full standalone report. JSON contains the report document. |
| `php artisan clever:owned-diff [--json]` | Current code, comment, blank-line, and file counts. | Replaces only probe `c1`. JSON contains that probe result. |
| `php artisan clever:welds [--json]` | Literal constructors, static calls, and recognized facade calls. | Replaces only probe `c2`. JSON contains that probe result. |
| `php artisan clever:lonely-files [--json]` | Single-author PHP files and their commit counts. | Replaces only probe `c3`. JSON contains that probe result. |
| `php artisan clever:hotspots [--json]` | Commit counts beside current code lines. | Replaces only probe `c4`. JSON contains that probe result. |

The default standalone report path is `storage/molly/complexity/report.json`. Individual commands preserve the other probe sections. A missing report starts a new document; a corrupt report starts a new document and prints a warning in terminal output. Task scans use separate files under the run evidence directory.

The full report contains `schema_version`, `generated_at`, `app_name`, `environment`, Git context, `package_version`, and a `probes` object keyed by probe ID. Each probe result contains `key`, `name`, `prints`, `status`, `skip_reason`, `metrics`, `headline`, `hand_verify`, `pairs_with`, `caveats`, `notes`, `warnings`, and `duration_ms`.

Clever commands exit `0` when the selected probes have no errors, including when a probe is skipped. A probe error returns `1`. A successful exit does not establish that every measurement ran. Inspect every probe's `status` before using the metrics.

Molly does not register `clever:*` commands when measurements are disabled. Artisan then reports an unavailable command. If measurements become disabled after command registration, the handler returns `1` with a text error. Neither path guarantees JSON, even with `--json`. Check the exit code before decoding output. JSON output is the saved report or probe result once a scan runs.

Read [measurement scope and limitations](../verification.md#clever-measurements) before comparing results. The implementation lives under `src/Complexity/Console/Commands/`.

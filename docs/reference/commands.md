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
| `--file=PATH` | An additional workspace-relative file Molly may change. Repeat for each file. Optional for a task that only changes the required test. |
| `--test=PATH` | Required Pest PHP test file under `tests/`. Also permits Molly to add or update that test; no duplicate `--file` option is needed. |
| `--json` | Print the command's JSON result instead of a terminal report. |

`molly:create` and `molly:import` also accept `--name=NAME` for an optional task nickname. `molly:run` creates a one-off run and has no nickname option.

Paths under `app/`, `routes/`, `resources/`, and `tests/` are accepted. Hidden segments, traversal, symbolic links, directories, and special files are rejected. See [task scope](../tasks.md#create-a-task) for size and count limits.

Run `php artisan molly:create` without arguments to answer these questions in order:

1. What should Molly work on?
2. Task nickname
3. Which Pest test should pass?
4. Which other files may Molly change?

Molly asks only for missing inputs. Leave the nickname empty to use the task UUID. Enter other files as a comma-separated list, or leave the answer empty for a task that only changes the test. These questions use Laravel Prompts. `molly:run` can ask for a missing prompt but still requires `--test`; `molly:import` requires its issue URL and `--test`.

Every public Molly command accepts `--json`. Commands that render run reports also honor Artisan's `--verbose` option for full measurement details and branch metadata. Use `--no-interaction` in scripts. `--json` and `--no-interaction` never ask questions. Supply the prompt for `molly:run` and `molly:create`, the issue URL for `molly:import`, and `--test` for all three commands. Missing required inputs return an error.

## Molly commands

The usage below shows Molly-specific arguments and options. `TASK` accepts a nickname such as `health-check` or a task UUID. `RUN_ID` accepts a run UUID. Normal Artisan options such as `--help`, `--verbose`, and `--no-interaction` remain available.

| Command | Usage | Result |
| --- | --- | --- |
| Set up agent | `php artisan molly:setup [--agent=AGENT] [--model=NAME] [--no-login] [--json]` | Selects `amp` or `ollama`, updates Molly's environment settings, and configures the workspace MCP connection for Amp. Interactive mode can ask for missing choices. |
| Open Amp chat | `php artisan molly:chat [--json]` | Opens Amp's terminal interface with Molly's stdio MCP connection. `--json` returns launch arguments without starting a session. |
| Plan tasks | `php artisan molly:plan [DESCRIPTION] [--skip-review] [--resume=PLAN_ID] [--step=STEP] [--answer=TEXT] [--json]` | Creates or reads a plan and saves ordered review answers. Planning does not execute a task. |
| Review PHP commit | `php artisan molly:review-commit [REF] [--staged] [--workspace=PATH] [--json]` | Reads a PHP diff, checks whitespace, and optionally asks Jev for a semantic evaluation. Defaults to `HEAD`; never runs tests or commits changes. |
| Run once | `php artisan molly:run [PROMPT] --test=PATH [--file=PATH] [--workspace=PATH] [--json]` | Executes a one-off run and saves its report. |
| Create task | `php artisan molly:create [PROMPT] [--name=NAME] [--test=PATH] [--file=PATH] [--workspace=PATH] [--json]` | Asks for missing inputs, then saves a pending task without calling the model or editing selected files. |
| Import issue | `php artisan molly:import ISSUE_URL --test=PATH [--name=NAME] [--file=PATH] [--workspace=PATH] [--json]` | Reads a GitHub issue through `gh api` and saves a pending task. |
| List tasks | `php artisan molly:tasks [--limit=20] [--json]` | Lists the newest saved tasks first. The limit must be an integer from 1 through 100. |
| Read task | `php artisan molly:task TASK [--json]` | Reads a saved task and its attempts, ordered oldest first. |
| Name task | `php artisan molly:name TASK [NAME] [--json]` | Sets or changes the nickname without replacing the task or its history. Asks for a missing name in interactive mode. |
| Link Amp thread | `php artisan molly:link-thread TASK THREAD_ID [--json]` | Records a user assertion linking an Amp thread to the task. Does not contact Amp or start work. |
| Read task connections | `php artisan molly:connections TASK [--stored] [--json]` | Reads exact thread associations and checks Amp executor state. `--stored` reads associations without contacting Amp. |
| Start task | `php artisan molly:start TASK [--json]` | Starts a pending task in the current process. |
| Retry task | `php artisan molly:retry TASK [--json]` | Creates a new attempt for a failed or stopped task within its attempt limit. |
| Stop task | `php artisan molly:stop TASK [--json]` | Stops a pending task or requests an active task to stop. Can settle an interrupted task after the locks are free. |
| Read run | `php artisan molly:show RUN_ID [--json]` | Reads a saved run report without executing the model again. |
| Export journal | `php artisan molly:journal TASK [--project] [--json]` | Writes the task's saved evidence to Markdown. `--project` instead refreshes the workspace journal and glossary and records the result. |
| Read task advice | `php artisan molly:advice TASK [--json]` | Explains the next step from saved state and attempt limits, with optional TypeSafe guidance. May save advice but never starts, retries, or stops the task. |
| Check setup | `php artisan molly:doctor [--workspace=PATH] [--json]` | Checks database history, workspace Pest, the selected Amp account or local Ollama model, measurements, and POSIX support when parallel checks are enabled. |

`molly:import` requires an HTTPS `github.com` issue URL without a query or fragment. Import cannot execute a pull request or write back to GitHub. See [GitHub import](../tasks.md#import-a-github-issue) for size limits and saved source fields.

Nicknames are unique across the host application. Molly trims surrounding spaces and stores lowercase names. A name must start with an ASCII letter and contain 1 through 64 ASCII letters, digits, or hyphens. UUID-shaped names are reserved. See [task nicknames](../tasks.md#name-a-task) for naming and renaming examples.

`molly:name` requires `NAME` when `--json` or `--no-interaction` is set. Without those options, a missing name opens the question `What should this task be called?`.

`molly:link-thread` requires both arguments. `THREAD_ID` is the `T-` prefix followed by a UUID, without a URL. Molly normalizes the ID and preserves earlier associations when the same thread is linked to another task. A saved link does not verify that the Amp thread exists or is working on the task.

`molly:connections` checks at most 20 linked threads and returns `truncated: true` when more are available. Live checks require an authenticated `amp` executable available to the host process. Stored-only reads report unknown connection state. See [task connections](../connections.md) for association ordering, state meanings, read limits, and web and MCP usage. Orb connect and disconnect controls remain [planned](../execution-targets.md).

Doctor checks readiness for task execution. Doctor does not run the model, execute the required tests, or check the web queue reservation settings.

`molly:setup` requires `--agent` in noninteractive mode. Ollama setup also requires an installed `--model`; Amp setup rejects that option. `--no-login` skips Amp's interactive login but still writes the workspace MCP connection. `--json` returns follow-up commands instead of running login. See [agent setup](../agents.md).

`molly:chat` requires an interactive terminal unless `--json` is set. The command always opens Amp and does not change the selected task provider. `php artisan mcp:start molly` is the Laravel MCP stdio entry point registered in local and testing environments. The [MCP guide](../agents.md#mcp-tools) lists the exposed operations.

`molly:plan` needs a description in noninteractive mode unless `--resume` is present. `--resume` cannot be combined with a new description or `--skip-review`. Save one answer with `--resume`, `--step`, and `--answer` together. Step IDs are `outcome`, `state`, `laravel`, `boundaries`, and `verification`. Descriptions allow 1 through 1,500 UTF-8 bytes; answers allow 1 through 600. See [planning](../planning.md).

`molly:review-commit` accepts a commit reference or `--staged`, not both. The workspace defaults to the host application. PHP diffs above 16 KiB fail before evaluation. TypeSafe is disabled by default; a disabled or empty evaluation remains unevaluated even when the command exits zero. See [commit review](../agents.md#review-a-php-commit).

`molly:advice` returns a recommendation separately from retry permission. Deterministic guidance remains available without TypeSafe. A TypeSafe request is possible only for a failed task with failed-attempt evidence and attempts remaining. See [task advice](../task-advice.md).

`molly:journal` writes `.molly/journal/TASK_UUID.md` in the task workspace. `--project` writes `.molly/JOURNAL.md` and updates Molly's marked section in `.molly/GLOSSARY.md`. Exporting leaves task and run lifecycle state unchanged. See the [journal guide](../journal.md) for automatic refreshes, selected evidence, and write failures.

The internal `molly:check` command belongs to the parallel executor. Use the public task commands to run work. The internal input and output arguments are not a user-facing API.

## JSON results and exit codes

The public Molly commands return exit code `0` on the success conditions below and `1` for a handled command failure. Artisan can report its own error for invalid command syntax before Molly's handler runs.

| Command | Successful JSON fields | Exit code `0` means |
| --- | --- | --- |
| `molly:setup` | `status`, `agent`, `model`, `next_commands` | Agent settings and any requested Amp MCP configuration were saved. `status` is `configured`; authentication is not implied. |
| `molly:chat --json` | `command`, `workspace` | Molly returned the Amp launch arguments without starting a chat. |
| `molly:plan` | `id`, `status`, `plan`, `next_step`, `sources` | Molly read or saved the plan. `status` can be `draft` or `ready`. |
| `molly:review-commit` | `scope`, `revision`, `diff_sha256`, `diff_bytes`, `file_scope`, `diff_check`, `tests`, `evaluation`, `citations` | The whitespace check passed and evaluation was disabled, not applicable, or evaluated as `continue`. Tests remain `not_run`. |
| `molly:run` | `id`, `status`, `report` | The new run completed. |
| `molly:create`, `molly:import` | `id`, `status`, `task` | Molly saved a pending task. |
| `molly:tasks` | `tasks` | Molly read the list, including an empty list. |
| `molly:task` | `id`, `status`, `task` | Molly found the task, regardless of its saved status. |
| `molly:name` | `id`, `status`, `task` | Molly saved the nickname, regardless of the task's saved status. |
| `molly:link-thread` | `id`, `task_id`, `thread_id`, `linked_at`, `source` | Molly recorded the link or returned the identical latest association. `id` is the association's integer ID; `source` is `user`. |
| `molly:connections` | `task_id`, `task`, `status`, `reason`, `observed_at`, `provider_version`, `matches`, `truncated`, `orb_identity` | Molly returned a report, including an empty result or an unavailable Amp observation. |
| `molly:advice` | `task_id`, `run_id`, `task_reference`, `status`, `next_action`, `reason`, `retry_allowed`, `command`, `observed`, `evidence_refs`, `provider`, `confidence`, `fallback`, `persisted`, `recorded_at` | Molly returned advice. The recommendation may be to stop or inspect; success does not mean another attempt is allowed. |
| `molly:start`, `molly:retry` | `id`, `task_id`, `status`, `report` | The new run completed. |
| `molly:stop` | `id`, `status`, `task` | Molly processed the stop request or read an already finished task. An active task may still be stopping. |
| `molly:show` | `id`, `status`, `report` | Molly found the run, regardless of its saved status. |
| `molly:journal` | `path`, `task_id`, `attempt_count` | Molly wrote the task journal. |
| `molly:journal --project` | `task_id`, `status`, `checked_at`, `journal_path`, `glossary_path` | Molly wrote both project files and recorded `status: "written"`. |
| `molly:doctor` | `ready`, `checks` | Every readiness check passed. |

Interactive `molly:chat` forwards Amp's process exit code. A noninteractive launch without `--json` fails with exit code `1` and prints the command to run in a terminal. `molly:review-commit` exits `1` for a failed whitespace check or an evaluation requesting `retry`, `stop`, or `needs_review`; the JSON report still contains the review evidence.

Successful task and run JSON responses retain UUIDs in `id` and `task_id` when a command receives a nickname. The `task` object includes a nullable `nickname`, saved prompt, workspace, selected `paths`, `test_path`, status, source metadata, and timestamps. `molly:task` also includes the `runs` relationship. A run report's available fields depend on how far execution progressed. Read the `status` and failure details before assuming a report contains verification or review evidence.

Connection reports use `task_id` for the canonical UUID and `task` for the current nickname or UUID string. Each `matches` entry contains `thread_id`, `url`, `association`, `association_id`, `linked_at`, `source`, `latest_task`, `connection`, `working`, `executor_type`, and `reason`. `latest_task` contains `id` and `reference`. Association values are `latest_recorded` and `prior_exact`; both describe user-recorded history. Connection values are `connected`, `disconnected`, and `unknown`. `working` is a boolean or `null`. The raw `executor_type` is a string or `null`, and `orb_identity` remains `unverified`.

The connection report's `status` is `observed`, `unknown`, `unavailable`, or `not_checked`. A successful command exit does not establish a connected executor. Inspect each match and `reason`. `observed_at` is the host's check time, not a heartbeat or association time. Stored-only reports have `observed_at: null` and `provider_version: null`.

For example, the following command emits one JSON object and exits nonzero if the new run fails:

```bash
php artisan molly:run \
  'Add GET /health returning JSON {"status":"ok"} and test the exact response.' \
  --file=routes/web.php \
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
| `molly:setup`, `molly:chat` | Handled setup or missing-executable errors contain `status: "failed"` and `error`. |
| `molly:plan` | Requested resume ID or `null` in `id`, `status: "error"`, and `error`. |
| `molly:review-commit` | `status: "error"` and `error` when no review report can be returned. |
| `molly:advice` | Requested reference in `task`, `status: "error"`, and `error`. |
| `molly:journal` | Requested reference in `task`, `status: "error"`, and `error`. With `--project`, a handled export failure instead returns `task_id`, `status: "unavailable"`, `reason`, and `checked_at`. Both failures exit `1`. |
| `molly:run` | `id: null`, `status: "failed"`, and `report.error` when no run is returned. |
| `molly:create`, `molly:import` | `id: null`, `status: "error"`, and `error`. |
| `molly:task`, `molly:stop`, `molly:name` | Requested task reference in `id`, `status: "error"`, and `error`. The reference may be a nickname or UUID. |
| `molly:link-thread`, `molly:connections` | `status: "error"` and `error`. An unavailable Amp observation is a report with `status: "unavailable"`, not this command-error structure. |
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

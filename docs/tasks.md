---
layout: default
title: Save and manage tasks
---

# Save and manage tasks

Save a task when you want attempt history, retries, or stop controls. Use `molly:run` for a single execution whose report you can read later. Both paths apply the same [verification and review rules](verification.md).

Complete the [first-run setup](getting-started.md) before starting a task. The [web interface](web-interface.md) calls the same task actions through a queue worker.

## Create a task

Run the task form:

```bash
php artisan molly:create
```

Molly asks what to change, an optional task nickname, which Pest test should pass, and which other files may change. Enter workspace-relative paths and separate other files with commas. Selecting the test adds that test to the editable scope. Leave the other-files answer empty for a task that only changes the test.

You can also provide the answers as arguments and options. Molly asks only for missing inputs. For scripts, supply the prompt and test, then add `--json --no-interaction`:

```bash
php artisan molly:create \
  'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.' \
  --name=ready-check \
  --file=routes/web.php \
  --test=tests/Feature/ReadyTest.php \
  --json --no-interaction
```

Creation validates the prompt, nickname, and file scope, then saves a pending task. Molly does not call the model or edit the selected files during creation. The examples below use the `ready-check` nickname. You can also use the task UUID printed by Molly.

`--json` and `--no-interaction` never ask questions. Missing required inputs return an error. Repeat `--file` for each additional file a script allows Molly to edit. Omit `--file` for a task that only changes the test.

Choose files relative to the workspace under `app/`, `routes/`, `resources/`, or `tests/`. Paths cannot contain hidden segments, traversal, or symbolic links. Select regular files or paths for new files, not directories. Molly allows eight distinct files by default, including the required test, with at most 65,536 bytes per file or replacement. The required `.php` test file must be under `tests/`. `--test` permits test edits without a duplicate `--file` option.

The host application is the default workspace. Add `--workspace=../another-checkout` to work in another existing checkout. That checkout needs its own Pest installation. Task history and evidence still belong to the host application where you run Artisan.

## Name a task

A nickname gives a task a readable reference such as `ready-check`. Nicknames are optional and unique within the host application, including tasks for other workspaces. Molly trims surrounding spaces and stores nicknames in lowercase. Use 1 through 64 ASCII letters, digits, or hyphens, starting with a letter. UUID-shaped names are reserved.

Set `--name` during creation or GitHub import. To name an existing task, use its UUID:

```bash
php artisan molly:name TASK_UUID ready-check
```

Replace `TASK_UUID` with the existing task's UUID. To rename a named task, use the current nickname. This example changes `previous-name` to `ready-check`:

```bash
php artisan molly:name previous-name ready-check
```

Renaming changes the nickname without replacing the task or its attempt history. Later commands use the new nickname or the unchanged UUID.

The task, start, retry, stop, and name commands accept either a nickname or UUID. Run reports still use run IDs. After upgrading Molly, run `php artisan migrate` before using nicknames.

You can omit the new name from `molly:name` to answer an interactive question. Scripts must provide the new name and can use `--json --no-interaction`.

## Start and inspect

```bash
php artisan molly:tasks --limit=20
php artisan molly:task ready-check
php artisan molly:start ready-check
```

`molly:start` accepts a pending task and runs in the current terminal. The command records a linked run before execution. A run completes only when the required tests, Tarpit review, and measurement checks pass. A passing model review cannot override a failed test.

Read the task again to see its attempts. Use a run ID from that history to inspect one report without executing the model again:

```bash
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
```

The normal report shows test evidence, all seven Tarpit checks, unresolved findings, and separate Clever measurements. Verbose output includes measurement details, hand-verification commands, and recorded branch identifiers and result paths.

## Retry a failed or stopped task

Review the failed report and applied edits before retrying:

```bash
php artisan molly:task ready-check
php artisan molly:retry ready-check
```

Each retry creates a new run. Earlier reports remain in task history. The default limit is three attempts, including the first execution. Molly does not retry automatically. A retry of a pending, running, or completed task fails without changing that task.

The writer receives the latest attempt's test status, counts, reason, and an output excerpt. The retry also includes up to three review findings for allowed files, with blocking findings first, plus a bounded run error when present. The encoded diagnostic input stays below 8,192 bytes. The writer must use the original task and file scope and must not weaken assertions to hide a failure.

Molly sends these diagnostics separately as `previous_attempt`. The retry does not send the complete previous report, provider configuration, or source snapshots. A first attempt, or a stopped task with no prior run, has no previous-attempt diagnostics. `src/Actions/StartTask.php` selects the evidence and `src/Actions/GenerateChanges.php` passes the evidence to the writer.

## Stop a task

Run the stop command in another terminal while a saved task is active:

```bash
php artisan molly:stop ready-check
php artisan molly:task ready-check
```

A pending task stops immediately. For an active task, Molly saves a stop request and checks the request between execution stages. Molly checks again after generation and before applying the proposed edits. Generation itself continues until the model request returns or times out.

During parallel verification and review, a stop request terminates both active check processes and their process groups, including Pest child processes. With serial checks, Molly waits for the active Pest or review call to finish. Applied edits remain in the workspace for inspection.

A successful stop command means Molly accepted or read the request. The task may still show `running` until execution reaches a stop boundary. Read the task again for the final state. Calling stop on a completed or failed task does not change the saved status.

If the original process exited without saving a final status, `molly:stop` can settle the interrupted task and running attempt after the task, workspace, and active-check locks are free. A busy or invalid lock does not establish that execution stopped. Saved evidence remains available, and a stopped task can be retried within its attempt limit. See [process and workspace coordination](verification.md#process-and-workspace-coordination) for the locking details.

## Import a GitHub issue

GitHub import requires an installed, authenticated `gh` command with access to the issue. GitHub access is optional for all other Molly commands.

```bash
php artisan molly:import https://github.com/OWNER/REPOSITORY/issues/NUMBER \
  --name=issue-fix \
  --file=app/Example.php \
  --test=tests/Feature/ExampleTest.php
```

Replace the URL and file paths with the issue and scope you intend to work on. Import reads the issue through `gh api`, then creates a pending task. Import does not execute the task or write to GitHub. Inspect the imported task with `molly:task` before starting the task.

The saved source context includes the repository, issue number, URL, title, update time, labels, and a null linked-PR value. The issue title and body become task context. Issue text is not test evidence.

Molly accepts HTTPS `github.com` issue URLs without a query string or fragment. Pull requests and responses that identify a different issue fail validation. The complete generated task prompt must fit within 8,192 bytes. Oversized imports fail without truncation; create a smaller task manually for a large issue. GitHub-to-Pest todo generation is not implemented.

## States and failures

| Task state | What you can do |
| --- | --- |
| `pending` | Inspect, start, or stop the task. |
| `running` | Inspect current evidence or request a stop. |
| `completed` | Review the report and diff. Create another task for new work. |
| `failed` | Inspect the failure and retry within the attempt limit. |
| `stopped` | Inspect retained edits and evidence, then retry within the attempt limit. |

Molly allows one writing run per workspace. Starting another run against a busy workspace fails with `WORKSPACE_BUSY`. Repeated start or retry requests cannot execute the same task concurrently. These guarantees require runners to share the local filesystem.

Failed verification or review leaves applied edits in place. Molly does not commit the changes. One-off `molly:run` executions have saved reports but no task retry or stop controls. Automatic resume is not implemented.

See the [command reference](reference/commands.md) for JSON fields and exit codes, or [troubleshooting](troubleshooting.md) for a failed check.

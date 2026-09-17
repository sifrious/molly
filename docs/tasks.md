---
layout: default
title: Save and manage tasks
---

# Save and manage tasks

Save a task when you want attempt history, retries, or stop controls. Use `molly:run` for a single execution whose report you can read later. Both paths apply the same [verification and review rules](verification.md).

Complete the [first-run setup](getting-started.md) before starting a task. The [web interface](web-interface.md) calls the same task actions through a queue worker.

## Create a task

Describe one change, name the files Molly may edit, and select the Pest test file that must pass:

```bash
php artisan molly:create \
  'Add GET /ready returning JSON {"ready":true}. Test the status code and exact response.' \
  --file=routes/web.php \
  --file=tests/Feature/ReadyTest.php \
  --test=tests/Feature/ReadyTest.php
```

Creation validates the prompt and file scope, then saves a pending task. Molly does not call the model or edit the selected files during creation. Copy the task ID from the output to use in later commands. `TASK_ID` below is a placeholder for that ID.

Omit the prompt to answer an interactive Laravel Prompts question. Scripts must supply the prompt and can add `--json --no-interaction`.

Choose files relative to the workspace under `app/`, `routes/`, `resources/`, or `tests/`. Paths cannot contain hidden segments, traversal, or symbolic links. Select regular files or paths for new files, not directories. Molly allows eight distinct files by default, with at most 65,536 bytes per file or replacement. The required `.php` test file must be under `tests/` and also appear in `--file`.

The host application is the default workspace. Add `--workspace=../another-checkout` to work in another existing checkout. That checkout needs its own Pest installation. Task history and evidence still belong to the host application where you run Artisan.

## Start and inspect

```bash
php artisan molly:tasks --limit=20
php artisan molly:task TASK_ID
php artisan molly:start TASK_ID
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
php artisan molly:task TASK_ID
php artisan molly:retry TASK_ID
```

Each retry creates a new run. Earlier reports remain in task history. The default limit is three attempts, including the first execution. Molly does not retry automatically. A retry of a pending, running, or completed task fails without changing that task.

The writer receives the latest attempt's test status, counts, reason, and an output excerpt. The retry also includes up to three review findings for allowed files, with blocking findings first, plus a bounded run error when present. The encoded diagnostic input stays below 8,192 bytes. The writer must use the original task and file scope and must not weaken assertions to hide a failure.

Molly sends these diagnostics separately as `previous_attempt`. The retry does not send the complete previous report, provider configuration, or source snapshots. A first attempt, or a stopped task with no prior run, has no previous-attempt diagnostics. `src/Actions/StartTask.php` selects the evidence and `src/Actions/GenerateChanges.php` passes the evidence to the writer.

## Stop a task

Run the stop command in another terminal while a saved task is active:

```bash
php artisan molly:stop TASK_ID
php artisan molly:task TASK_ID
```

A pending task stops immediately. For an active task, Molly saves a stop request and checks the request between execution stages. Molly checks again after generation and before applying the proposed edits. Generation itself continues until the model request returns or times out.

During parallel verification and review, a stop request terminates both active check processes and their process groups, including Pest child processes. With serial checks, Molly waits for the active Pest or review call to finish. Applied edits remain in the workspace for inspection.

A successful stop command means Molly accepted or read the request. The task may still show `running` until execution reaches a stop boundary. Read the task again for the final state. Calling stop on a completed or failed task does not change the saved status.

If the original process exited without saving a final status, `molly:stop` can settle the interrupted task and running attempt after the task, workspace, and active-check locks are free. A busy or invalid lock does not establish that execution stopped. Saved evidence remains available, and a stopped task can be retried within its attempt limit. See [process and workspace coordination](verification.md#process-and-workspace-coordination) for the locking details.

## Import a GitHub issue

GitHub import requires an installed, authenticated `gh` command with access to the issue. GitHub access is optional for all other Molly commands.

```bash
php artisan molly:import https://github.com/OWNER/REPOSITORY/issues/NUMBER \
  --file=app/Example.php \
  --file=tests/Feature/ExampleTest.php \
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

# Tasks

A task is a saved request: what should change, which Pest test proves it, and which files the agent may edit. A run is one attempt to complete that task. This page covers creating, starting, inspecting, retrying, and stopping tasks from Artisan.

## Create a task

```bash
php artisan molly:create
```

Molly asks for the request, an optional nickname, the required Pest test, and the other files it may change. The same task from a script:

```bash
php artisan molly:create \
  'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.' \
  --name=ready-check \
  --test=tests/Feature/ReadyTest.php \
  --file=routes/web.php \
  --json --no-interaction
```

Creating a task saves it and nothing else. The model is not called and no file changes.

The test must already exist. Molly records its digest and protects it from the agent for the life of the task. If you want the agent to write the test, see [Write the test first](tutorials.md#write-the-test-first).

### File scope

Molly accepts paths under `app/`, `routes/`, `resources/`, and `tests/`. By default a task may name up to eight writable files, each up to 64 KB, and the protected test does not count toward that limit. Hidden paths, symlinks, directories, and paths that leave the workspace are rejected. `--workspace=PATH` points the task at another checkout; the default is the application itself.

## Start a task

```bash
php artisan molly:start ready-check
```

The run happens in your terminal: the model proposes a change, Molly applies it, runs the test, reviews the diff, and records the result. The command prints the run ID and exits `0` when the run completed, `1` otherwise. No queue worker is needed for Artisan.

Molly refuses to start a second run in the same workspace while one is active (`WORKSPACE_BUSY`), and refuses to start a task that already completed.

## Inspect tasks and runs

```bash
php artisan molly:tasks
php artisan molly:task ready-check
php artisan molly:show RUN_ID --verbose
php artisan molly:receipt RUN_ID
```

`molly:tasks` lists the newest tasks. `molly:task` shows one task, its display status (for example `awaiting_approval` after a completed run), any recorded pull request, and every attempt. `molly:show` reads a run's evidence, and `molly:receipt` reads its verification receipts. Reading never calls the model.

`molly:inspect` follows the links between a task, its runs, and the saved conversations:

```bash
php artisan molly:inspect ready-check --ensure-conversations
php artisan molly:inspect --run=RUN_ID
```

## Retry a failed task

```bash
php artisan molly:retry ready-check
```

A retry creates another run and keeps the earlier one. Molly sends the model a bounded summary of the previous failure. A task may make three attempts in total by default, counted across starts and retries; the limit is `molly.max_attempts`. Molly never retries on its own.

Read the failed run before retrying. If the failure is in the test itself, fix the test in a new task rather than weakening it.

## Stop a task

```bash
php artisan molly:stop ready-check
```

A pending task stops immediately. A running task is asked to stop at its next step, and Molly records the request. Applied edits may already be in the working tree, so check `git status` afterward. A stopped task can be retried.

## Name a task

```bash
php artisan molly:name TASK_UUID ready-check
php artisan molly:name old-name new-name
```

A nickname starts with a letter and uses lowercase letters, digits, and hyphens. Renaming keeps the UUID and the run history.

## Ask what to do next

```bash
php artisan molly:advice ready-check
```

Advice reads the saved state and the attempt limit and tells you which action is allowed: start, inspect, retry, stop, or done. It never performs the action. [Task advice](task-advice.md) covers the optional Jev suggestion.

## Approve, then record the pull request

After a run completes, the task waits for a person:

```bash
php artisan molly:approve ready-check --approve
php artisan molly:pr-body ready-check
```

`molly:approve` records that you reviewed the change. `molly:pr-body` then prints a pull request description built from the evidence. Molly does not open or merge pull requests. When a person does, record it:

```bash
php artisan molly:pr-opened ready-check --url https://github.com/OWNER/REPO/pull/123 --approve
php artisan molly:merged ready-check --sha MERGE_SHA --approve
```

## Task states

| State | Meaning |
| --- | --- |
| `pending` | Saved and ready to start. |
| `running` | An attempt is in progress. |
| `completed` | The required checks passed. Review the diff before committing. |
| `failed` | The attempt ended without the evidence needed to complete. |
| `stopped` | Work stopped before completion. |

`molly:task` also shows a display status that folds in approvals and pull requests: `awaiting_approval`, `approved`, `handed_off`, and `merged`.

## One-off runs

To make a change without saving a reusable task:

```bash
php artisan molly:run 'Rename the greeting method.' \
  --test=tests/Feature/GreetingTest.php \
  --file=app/Greeting.php
```

The run saves a report but cannot be retried or stopped later.

## Next

- [Verification](verification.md)
- [Task advice](task-advice.md)
- [Tutorials](tutorials.md)
- [Commands](reference/commands.md)

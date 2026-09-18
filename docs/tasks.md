---
layout: default
title: Manage tasks
---

# Manage tasks

Use this page when you want to save a task, start it later, inspect attempts, retry a failure, or stop work.

A **task** is the saved request. A **run** is one attempt to execute that task.

## The normal task flow

```bash
php artisan molly:create
php artisan molly:start TASK
php artisan molly:task TASK
php artisan molly:show RUN_ID --verbose
```

`TASK` can be a nickname such as `health-check` or the task UUID.

Imported GitHub issues stay pending until you start them. After a human approves writeback, `molly:comment` can post a status comment and `molly:pr-body` can print a pull request body. Molly does not open or merge the pull request.

## Create a task

Interactive creation:

```bash
php artisan molly:create
```

Scripted creation:

```bash
php artisan molly:create \
  'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.' \
  --name=ready-check \
  --file=routes/web.php \
  --test=tests/Feature/ReadyTest.php \
  --json --no-interaction
```

Creating the task does not call the model. It saves a pending task with:

- the request
- an optional nickname
- the required Pest test
- the files Molly may edit
- the workspace

The required Pest test is protected by default. Molly records its SHA-256 digest and rejects proposals that change it. Pass `--allow-test-edits` only when a separate test-authoring task should change that file. The journal records that weaker trust model.

## File scope

Molly accepts selected paths under `app/`, `routes/`, `resources/`, and `tests/`.

By default:

- At most 8 distinct writable files may be selected. The protected test does not count toward that limit.
- Each selected file or proposed replacement may be at most 65,536 bytes.
- The required test must be a PHP file under `tests/`.
- Hidden paths, path traversal, symlinks, directories, and special files are rejected.

The file scope limits proposed edits. Writer and Pest processes also run in a Landlock sandbox with a network namespace when `molly:doctor` reports that the host can isolate them.

## Name or rename a task

```bash
php artisan molly:name TASK_UUID ready-check
php artisan molly:name old-name new-name
```

Nicknames are optional. A nickname starts with a letter and may contain lowercase letters, digits, and hyphens after normalization. It may be up to 64 characters.

Renaming does not change the task UUID or its run history.

## Start a task

```bash
php artisan molly:start ready-check
```

A pending task runs in the current terminal. CLI execution does not need a queue worker.

A run completes only when the required evidence passes. A model review cannot override failed Pest tests.

## Inspect tasks and runs

```bash
php artisan molly:tasks --limit=20
php artisan molly:task ready-check
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
```

Reading saved evidence does not call the model again.

## Retry a failed or stopped task

```bash
php artisan molly:retry ready-check
```

A retry creates another run and keeps the earlier evidence. The default attempt limit is three total runs, including the first.

Molly never retries automatically.

Before retrying, read the failed run. Molly sends a bounded part of the previous failure evidence to the next writer so it can address the failure without receiving the entire old report.

## Stop a task

```bash
php artisan molly:stop ready-check
```

For a pending task, stop is immediate. For active work, Molly records a stop request and checks it between execution stages.

Generated changes may already be present. Always inspect the working tree after stopping.

## Import a GitHub issue

If the authenticated `gh` CLI can read the issue:

```bash
php artisan molly:import https://github.com/OWNER/REPOSITORY/issues/NUMBER \
  --name=issue-fix \
  --file=app/Example.php \
  --test=tests/Feature/ExampleTest.php
```

Import creates a pending task. It does not start the task or write anything back to GitHub.

## Task states

| State | Meaning |
| --- | --- |
| `pending` | Saved and ready to start. |
| `running` | An attempt is active. |
| `completed` | Required evidence passed. Review the diff before committing. |
| `failed` | An attempt finished without the evidence needed to complete. |
| `stopped` | Work stopped before completion. Inspect retained changes and evidence. |

## One writer per workspace

Molly prevents two writing runs from using the same workspace at the same time. If you see `WORKSPACE_BUSY`, check for active work before retrying.

Failed verification does not roll back an already applied proposal. Molly also does not commit changes for you.

## Next

- [Verification](verification.md)
- [Task advice](task-advice.md)
- [Task connections](connections.md)
- [Command reference](reference/commands.md)

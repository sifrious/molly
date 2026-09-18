---
layout: default
title: Command reference
---

# Command reference

Use this page when you already know the feature you want and need the exact Artisan command.

For a first run, use [Getting started](../getting-started.md) instead.

Every public Molly command supports `--json`. Use `--no-interaction` in scripts when you do not want prompts.

## Everyday task commands

| Command | What it does |
| --- | --- |
| `php artisan molly:create` | Saves a pending task. Does not call the model. |
| `php artisan molly:start TASK` | Starts a pending task in the current terminal. |
| `php artisan molly:tasks` | Lists saved tasks. |
| `php artisan molly:task TASK` | Reads one task and its attempt history. |
| `php artisan molly:show RUN_ID` | Reads one saved run. |
| `php artisan molly:retry TASK` | Creates another attempt for a failed or stopped task if allowed. |
| `php artisan molly:stop TASK` | Stops a pending task or requests an active task to stop. |
| `php artisan molly:name TASK NAME` | Adds or changes a task nickname. |
| `php artisan molly:doctor` | Checks whether the selected workspace and provider are ready. |
| `php artisan molly:bloom-contract TASK` | Prints the versioned task contract for an existing Bloom workspace. Does not create a worktree. |
| `php artisan molly:approve TASK --approve` | Records human approval after required checks pass. Does not open a pull request. |
| `php artisan molly:comment TASK --approve` | Posts or updates a GitHub issue comment after explicit approval. |
| `php artisan molly:pr-body TASK` | Prints a pull request body that links the issue, acceptance test, and evidence. Does not open a pull request. |
| `php artisan molly:handoff TASK --from UUID --to UUID` | Prints a handoff envelope for a child Bloom workspace. Does not create a worktree. |

`TASK` accepts either a nickname or task UUID. `RUN_ID` is a run UUID.

## Setup and agent commands

| Command | What it does |
| --- | --- |
| `php artisan molly:setup --agent=ollama --model=NAME` | Saves a local Ollama provider and model. |
| `php artisan molly:setup --agent=amp` | Saves Amp as the provider and configures the workspace MCP connection. |
| `php artisan molly:chat` | Opens Amp with Molly's local MCP server available. |
| `php artisan mcp:start molly` | Runs Molly's stdio MCP server. |

`molly:chat --json` prints the launch details without opening an interactive session.

## One-off run

```bash
php artisan molly:run 'YOUR REQUEST' \
  --test=tests/Feature/ExampleTest.php \
  --file=app/Example.php
```

A one-off run saves a report but does not create a reusable task with retry or stop controls.

## Create a task non-interactively

```bash
php artisan molly:create 'YOUR REQUEST' \
  --name=example-task \
  --test=tests/Feature/ExampleTest.php \
  --file=app/Example.php \
  --json --no-interaction
```

Shared scope options:

| Option | Meaning |
| --- | --- |
| `--workspace=PATH` | Existing checkout to work in. Defaults to the host Laravel app. |
| `--test=PATH` | Required Pest PHP test under `tests/`. Protected unless `--allow-test-edits` is set. |
| `--file=PATH` | A writable implementation file. Repeat as needed. |
| `--allow-test-edits` | Permit this task to change the required Pest test. Not the default. |
| `--json` | Print structured JSON. |

Accepted edit paths stay under `app/`, `routes/`, `resources/`, and `tests/`.

## Planning

```bash
php artisan molly:plan 'DESCRIPTION'
php artisan molly:plan --resume=PLAN_ID
```

Useful options:

- `--skip-review`
- `--step=STEP`
- `--answer=TEXT`
- `--json`

Plan step IDs are `outcome`, `state`, `laravel`, `boundaries`, and `verification`.

## Laravel knowledge

```bash
php artisan molly:knowledge:index laravel
php artisan molly:knowledge:index laravel --laravel-version=13
php artisan molly:knowledge:query Queue
php artisan molly:knowledge:query Route
php artisan molly:knowledge:query Pest
php artisan molly:knowledge:query Retry --depth=1 --limit=10
php artisan molly:knowledge:query Job --relation=uses
```

Query limits:

- depth: 0 through 3
- node limit: 1 through 40
- `--relation` may be repeated

The requested Laravel major version must match the installed major version.

## Project graph

```bash
php artisan molly:project:index
php artisan molly:project:query TASK_UUID
php artisan molly:project:query pest --relation=blocked_by
```

The project graph is separate from Laravel queue, routing, and testing knowledge. It is rebuilt from saved tasks and attempts in the named workspace.

## GitHub issue import

```bash
php artisan molly:import https://github.com/OWNER/REPO/issues/123 \
  --name=issue-fix \
  --test=tests/Feature/ExampleTest.php \
  --file=app/Example.php
```

This requires an authenticated `gh` CLI. It creates a pending task and does not start an agent.

After a human approves a comment:

```bash
php artisan molly:comment TASK --approve
php artisan molly:pr-body TASK
php artisan molly:pr-body TASK --close
```

`molly:comment` posts or updates one concise issue comment. `--close` adds closing language only after required checks pass. Molly still does not open or merge a pull request.

## Journals and advice

```bash
php artisan molly:journal TASK
php artisan molly:journal TASK --project
php artisan molly:advice TASK
```

`molly:journal` exports saved evidence. `molly:advice` explains the next allowed action and never executes it.

## Amp thread connections

```bash
php artisan molly:link-thread TASK T-00000000-0000-0000-0000-000000000000
php artisan molly:connections TASK
php artisan molly:connections TASK --stored
```

A saved link is a user assertion. `molly:connections` can read current Amp connection evidence, but it does not verify Orb identity.

## Review a PHP commit

```bash
php artisan molly:review-commit HEAD
php artisan molly:review-commit --staged --json
```

Choose a commit reference or `--staged`, not both. The command checks a bounded PHP diff and Git whitespace. Optional TypeSafe evaluation must be configured separately.

It does not run tests or create commits.

## Clever commands

```bash
php artisan clever:scan
php artisan clever:scan --json
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

These are measurements, not one combined quality score.

## JSON and exit codes

General rule:

- Exit `0` means the command completed its own requested operation.
- Exit `1` means Molly handled a command failure.
- A successful read command may return a saved task or run whose own status is `failed`.
- A successful `molly:connections` command may still report an unavailable or unknown connection. Inspect the JSON fields.
- `molly:start` and `molly:retry` return success only when the new run completes.

For scripts, read both the process exit code and the returned `status`, `ready`, `retry_allowed`, or connection fields that belong to the command.

## Internal command

`molly:check` belongs to Molly's internal parallel-check runner. Do not use it as a public API.

## Related guides

- [Manage tasks](../tasks.md)
- [Agents and MCP](../agents.md)
- [Configuration](configuration.md)
- [Troubleshooting](../troubleshooting.md)

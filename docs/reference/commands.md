---
layout: default
title: Commands
---

# Commands

Every Molly command takes `--json` for structured output. Add `--no-interaction` in scripts. `TASK` is a task nickname or UUID; `RUN_ID` is a run UUID.

For a first run, read [Getting started](../getting-started.md) instead of this page.

## Tasks

| Command | What it does |
| --- | --- |
| `molly:create [PROMPT]` | Saves a pending task. Does not call the model. |
| `molly:start TASK` | Runs a pending task in the terminal. Exits `0` only when the run completes. |
| `molly:retry TASK` | Starts another attempt for a failed or stopped task. |
| `molly:stop TASK` | Stops a pending task, or asks a running one to stop at its next step. |
| `molly:tasks [--limit=20]` | Lists saved tasks, newest first. |
| `molly:task TASK` | Shows one task, its display status, recorded pull request, and attempts. |
| `molly:show RUN_ID [--verbose]` | Shows a saved run's evidence. |
| `molly:receipt RUN_ID` | Shows the run's verification receipts from `.molly/receipts/`. |
| `molly:inspect [TARGET]` | Follows links between a task, its runs, and conversations. |
| `molly:name TASK NAME` | Gives a task a nickname. |
| `molly:advice TASK` | Says which action is allowed next. Never performs it. |
| `molly:run PROMPT --test=… --file=…` | Makes one change without saving a reusable task. |
| `molly:demo` | Writes the greeting demo files and saves `demo-greeting`. |

### Options shared by `create`, `run`, and `import`

| Option | Meaning |
| --- | --- |
| `--test=PATH` | The required Pest test under `tests/`. Protected unless `--allow-test-edits` is set. |
| `--file=PATH` | A file the agent may change. Repeat for several. |
| `--allow-test-edits` | Let this task write the required test. Use it for a test-authoring task only. |
| `--workspace=PATH` | Another checkout to work in. Defaults to the application. |
| `--name=NAME` | A nickname. |

Paths must be under `app/`, `routes/`, `resources/`, or `tests/`.

## Setup

| Command | What it does |
| --- | --- |
| `molly:doctor [--workspace=PATH]` | Checks the database, Pest, the sandbox, parallel checks, the agent, Clever, and the Jev gate. |
| `molly:setup --agent=ollama --model=NAME` | Saves a local Ollama model. |
| `molly:setup --agent=amp [--no-login]` | Saves Amp as the agent and connects its MCP client. |
| `molly:settings` | Shows global settings from `~/.molly/settings.json`. |
| `molly:settings-set --patch=JSON` | Merges a JSON patch into global settings. |
| `molly:project-init [PATH]` | Adds Molly to an existing Laravel project: Composer, config, migrations, graphs. |
| `molly:project-new [PATH]` | Creates a new Laravel project with Molly installed. |
| `molly:projects` | Lists projects in the shared registry Bloom also reads. |

`project-init` and `project-new` take `--no-composer`, `--no-migrate`, and `--no-graphs` to skip steps.

## Agents and MCP

| Command | What it does |
| --- | --- |
| `molly:chat [--json]` | Opens Amp with Molly's MCP server attached. |
| `mcp:start molly` | Runs the stdio MCP server for another client. |
| `molly:link-thread TASK T-…` | Saves a link between a task and an Amp thread. |
| `molly:connections TASK [--stored]` | Reads saved thread links, and Amp's connection state unless `--stored`. |

## After a run

| Command | What it does |
| --- | --- |
| `molly:approve TASK --approve` | Records that a person approved the verified change. |
| `molly:lock-test TASK --approve [--file=PATH] [--reason=TEXT]` | Locks a written Pest test and starts the implementation scope. |
| `molly:pr-body TASK [--close]` | Prints a pull request description after approval. `--close` adds closing language for an imported issue. |
| `molly:comment TASK --approve [--close]` | Posts or updates one GitHub issue comment. |
| `molly:pr-opened TASK --url URL --approve` | Records a pull request a person opened. |
| `molly:merged TASK --sha SHA --approve` | Records a merge a person made. |
| `molly:handoff TASK --from UUID --to UUID` | Prints a handoff envelope for a child Bloom workspace. |
| `molly:bloom-contract TASK --workspace-id=… --branch=… --base-sha=…` | Exports the task contract for a Bloom workspace. |
| `molly:review-commit [REF] [--staged]` | Checks a PHP diff for whitespace problems and, with Jev on, asks for a review. |

Molly never opens, comments on, or merges anything without `--approve`, and it never opens or merges a pull request at all.

## Planning

```bash
php artisan molly:plan 'DESCRIPTION'
php artisan molly:plan 'DESCRIPTION' --skip-review
php artisan molly:plan --resume=PLAN_ID
php artisan molly:plan --resume=PLAN_ID --step=outcome --answer='…' --json --no-interaction
```

Steps are `outcome`, `state`, `laravel`, `boundaries`, and `verification`.

## GitHub issues

```bash
php artisan molly:import https://github.com/OWNER/REPO/issues/123 \
  --name=issue-123 --test=tests/Feature/ExampleTest.php --file=app/Example.php
```

Needs a logged-in `gh`. Saves a pending task and writes nothing to GitHub.

## Laravel knowledge

| Command | What it does |
| --- | --- |
| `molly:knowledge:index [laravel\|nativephp\|tarpit] [--laravel-version=N]` | Builds one namespace of the knowledge graph. |
| `molly:knowledge:query CONCEPT [--namespace=…] [--nativephp-version=…] [--depth=2] [--limit=20] [--relation=…]` | Reads a neighborhood. Depth 0 to 3, limit 1 to 40. |
| `molly:knowledge:pack PROMPT [--file=…] [--test=…]` | Shows the context an implementation run would receive. |
| `molly:graphs-bootstrap [PATH]` | Builds the version-pinned graphs for a project. |
| `molly:graphs-retry UNIT [PATH]` | Retries one failed bootstrap unit from `.molly/graphs/manifest.json`. |

NativePHP queries need `--namespace=nativephp` with `--nativephp-version=desktop-2` or `mobile-4`. Tarpit queries need `--namespace=tarpit`.

## Project graph

| Command | What it does |
| --- | --- |
| `molly:project:index [--workspace=PATH]` | Rebuilds the graph from saved tasks and runs. |
| `molly:project:query CONCEPT [--depth=2] [--limit=20] [--relation=…]` | Reads a neighborhood around a task, test, file, run, or blocker. |

## Journals and decisions

| Command | What it does |
| --- | --- |
| `molly:journal TASK [--project]` | Writes `.molly/journal/TASK_UUID.md`; `--project` also refreshes the workspace journal and glossary. |
| `molly:decide --title=… --body=… [--task=TASK]` | Writes a decision record under `docs/decisions/`. |

## Clever

```bash
php artisan clever:scan
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

Each probe writes its section of `storage/molly/complexity/report.json` (or the path in `molly-complexity.report.path`). They are measurements, not a score, and they register only where Clever is enabled.

## Exit codes

`0` means the command did what you asked; `1` means Molly reported a failure. A read command exits `0` even when the run it shows failed, so scripts should read the returned `status`, `ready`, or `retry_allowed` field as well. `molly:start` and `molly:retry` exit `0` only when the new run completed.

`molly:check` is internal to the parallel check runner. Do not call it.

## Related

- [Tasks](../tasks.md)
- [Configuration](configuration.md)
- [Troubleshooting](../troubleshooting.md)

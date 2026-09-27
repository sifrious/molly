# Commands

Every `molly:` and `clever:` command in this reference takes `--json` for structured output. The exception is `molly:check`, which the parallel check runner calls with an input and an output file and which you should not call yourself. Add `--no-interaction` in scripts. `TASK` is a task nickname or UUID; `RUN_ID` is a run UUID.

For a first run, read [Getting started](../getting-started.md) instead of this page.

## Tasks

| Command | What it does |
| --- | --- |
| `molly:create [PROMPT]` | Saves a pending task. Does not call the model. |
| `molly:story [STORY] --test=… [--file=…]` | Asks the configured model for numbered acceptance criteria, saves them on a new test-authoring task, and prints the next three commands. |
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

### Options for `create`, `run`, and `import`

| Option | Accepted by | Meaning |
| --- | --- | --- |
| `--test=PATH` | `create`, `run`, `import` | The required Pest test under `tests/`. Protected unless `--allow-test-edits` is set. |
| `--file=PATH` | `create`, `run`, `import` | A file the agent may change. Repeat for several. |
| `--workspace=PATH` | `create`, `run`, `import` | Another checkout to work in. Defaults to the application. |
| `--allow-test-edits` | `create`, `import` | Let this task write the required test. Use it for a test-authoring task only. |
| `--name=NAME` | `create`, `import` | A nickname. |

`molly:run` always protects the required test and saves no nickname. To let a run write its test, or to name it, save it with `molly:create` and start it with `molly:start`.

Paths must be under `app/`, `routes/`, `resources/`, or `tests/`.

## Setup

| Command | What it does |
| --- | --- |
| `molly:doctor [--workspace=PATH]` | Checks the database, Pest, the sandbox, parallel checks, the agent, Clever, and the Jev gate. |
| `molly:preflight [--destination=PATH]` | Measures this Mac before a model download: architecture, Rosetta translation, macOS version, memory and memory pressure, Metal, the installed Ollama runtime and models, and free space on the volume that holds the models. Facts it cannot read are listed as unknown. It does not choose or download a model. |
| `molly:setup --agent=ollama --model=NAME` | Saves a local Ollama model. |
| `molly:setup --agent=amp [--no-login]` | Saves Amp as the agent and connects its MCP client. |
| `molly:settings` | Shows global settings from `~/.molly/settings.json`. |
| `molly:settings-set --patch=JSON` | Merges a JSON patch into global settings. |
| `molly:project-init [PATH]` | Adds Molly to an existing Laravel project: Composer, config, migrations, graphs. |
| `molly:project-new [PATH]` | Creates a new Laravel project with Molly installed. |
| `molly:projects` | Lists projects in the shared registry Bloom also reads. |
| `molly:status [--workspace=PATH]` | Shows readiness, running and pending tasks with lease expiry, the Molly worker, and effective settings. Reads only. |

`project-init` and `project-new` take `--no-composer`, `--no-migrate`, and `--no-graphs` to skip steps. Molly writes `.molly/project.json` and adds the project to the registry only after every step succeeds. If the Laravel graph cannot be built, the command exits 1 and the project is not listed.

## Queue worker

Queued starts from the web interface and MCP need a queue worker. `molly:worker` runs one for you:

| Command | What it does |
| --- | --- |
| `molly:worker start [--workspace=PATH]` | Starts `php artisan queue:work` on the default connection and queue as its own process group. Refuses when Molly's worker is already running. |
| `molly:worker status [--workspace=PATH]` | Reports `state` (`running`, `stopped`, or `stale`), pid, process group, uptime, queue, and log path. |
| `molly:worker stop [--workspace=PATH] [--timeout=30]` | Sends `SIGTERM` to the worker's process group, then `SIGKILL` after the timeout. |
| `molly:worker restart [--workspace=PATH] [--timeout=30]` | Stops the worker, then starts a new one. |

The worker is recorded in `.molly/worker/worker.json` and writes to `.molly/worker/worker.log`. A record is `stale` when its process has exited (`process_gone`) or its pid now belongs to a different command or process group (`pid_reused`). Molly never signals a stale pid; `start` replaces a stale record, and `stop` removes it. The command never prompts. See [Configuration](configuration.md#database-and-queue) for `molly.worker.php_binary`.

With `--json`, `molly:status` returns `workspace`, `checked_at`, `readiness` (the `molly:doctor` report), `tasks` (`scope`, `running`, and `pending`, each task with `worker_id`, `claimed_at`, `heartbeat_at`, `lease_expires_at`, and `lease_expired`), `worker` (the `molly:worker status` report), `graphs` (the [graph freshness](../knowledge-graph.md#stale-graphs) report for the workspace), and `config` (`molly` settings without credentials or prompts, and the `queue` connection, driver, queue, and `retry_after`). Without `--workspace` it lists tasks from every workspace. It exits `0` whenever it can read that state, even when a check failed, and `1` when it cannot.

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
| `molly:lock-test TASK --approve [--file=PATH] [--reason=TEXT]` | Locks a written Pest test, runs it once to record the RED baseline, and starts the implementation scope. Run it again to record a new baseline after fixing the test. |
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
| `molly:knowledge:query CONCEPT [--namespace=…] [--nativephp-version=…] [--depth=2] [--limit=20] [--relation=…] [--workspace=PATH]` | Reads a neighborhood. Depth 0 to 3, limit 1 to 40. Refuses with `GRAPH_STALE` when the graph was built for another exact package version. |
| `molly:knowledge:pack PROMPT [--file=…] [--test=…]` | Shows the context an implementation run would receive. |
| `molly:graphs-bootstrap [PATH]` | Builds the version-pinned graphs for a project. |
| `molly:graphs-retry UNIT [PATH]` | Retries one failed bootstrap unit from `.molly/graphs/manifest.json`. An unknown unit exits 1 with `UNIT_UNKNOWN` and lists the valid units. |

NativePHP queries need `--namespace=nativephp` with `--nativephp-version=desktop-2` or `mobile-4`. Tarpit queries need `--namespace=tarpit`.

Laravel and NativePHP query results include a `freshness` object; see [Stale graphs](../knowledge-graph.md#stale-graphs).

## Project graph

| Command | What it does |
| --- | --- |
| `molly:project:index [--workspace=PATH]` | Rebuilds the graph from saved tasks and runs. |
| `molly:project:query CONCEPT [--depth=2] [--limit=20] [--relation=…] [--workspace=PATH]` | Reads a neighborhood around a task, test, file, run, or blocker. The result includes `freshness`. |

## Journals and decisions

| Command | What it does |
| --- | --- |
| `molly:journal TASK [--project]` | Writes `.molly/journal/TASK_UUID.md`; `--project` also refreshes the workspace journal and glossary. |
| `molly:journal --project [--workspace=PATH]` | Refreshes `.molly/JOURNAL.md` and `.molly/GLOSSARY.md` without naming a task. |
| `molly:glossary [PATH] [--workspace=PATH]` | Lists the Molly terms that `molly:journal --project` writes to `.molly/GLOSSARY.md`. Each term has `id`, `term`, `definition`, `origin`, `provenance`, and `links`. Links resolve from the workspace; see [Journals](../journal.md#the-project-journal-and-glossary). Reads only; `exported` says whether the file exists yet. Definitions you add outside the managed section are not included. |
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

`molly:check INPUT OUTPUT` is internal to the parallel check runner. It takes no `--json` flag; it reads its input file and writes its result to the output file. Do not call it.

## Related

- [Tasks](../tasks.md)
- [Configuration](configuration.md)
- [Troubleshooting](../troubleshooting.md)

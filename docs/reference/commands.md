# Commands

Every `molly:` and `clever:` command in this reference takes `--json` for structured output. The exception is `molly:check`, which the parallel check runner calls with an input and an output file and which you should not call yourself. Add `--no-interaction` in scripts. `TASK` is a task nickname or UUID; `RUN_ID` is a run UUID.

For a first run, read [Getting started](../getting-started.md) instead of this page.

## Tasks

| Command | What it does |
| --- | --- |
| `molly:create [PROMPT]` | Saves a pending task. Does not call the model. |
| `molly:story [STORY] --test=… [--file=…]` | Asks the configured model for numbered acceptance criteria and the files the implementation will change, saves them on a new test-authoring task, and prints the next three commands. Run them as printed. |
| `molly:start TASK [--orb=ORB]` | Runs a pending task in the terminal. Exits `0` only when the run completes. Ctrl-C or `SIGTERM` stops the run at its next step, saves it as `stopped`, and exits `130` or `143`. With an [Orb option](#orbs), the run executes on that Orb. |
| `molly:retry TASK [--orb=ORB]` | Starts another attempt for a failed or stopped task, and recovers a task a killed process left `running`. Handles Ctrl-C and `SIGTERM` like `molly:start`, and takes the same Orb options. |
| `molly:queue TASK [--retry] [--orb=ORB]` | Queues a start, or a retry with `--retry`, for a queue worker and returns. With an Orb option, the job goes to that Orb's queue. The queue needs the settings in [Queue requirements](../web-interface.md#queue-requirements). `queued` means requested, not running or passed. |
| `molly:stop TASK` | Stops a pending task, or asks a running one to stop at its next step. |
| `molly:tasks [--limit=20]` | Lists saved tasks, newest first. |
| `molly:task TASK` | Shows one task, its display status, recorded pull request, and attempts. |
| `molly:show RUN_ID [--verbose]` | Shows a saved run's evidence. |
| `molly:receipt RUN_ID` | Shows the run's verification receipts from `.molly/receipts/`, with the run facts each receipt's digest covers, such as the model identity and the Orb. |
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
| `--todos` | `import` | Write one Pest todo per item under the issue's "Acceptance criteria" heading to the new test file. Needs `--allow-test-edits`. |

`molly:run` always protects the required test and saves no nickname. To let a run write its test, or to name it, save it with `molly:create` and start it with `molly:start`.

Paths must be under `app/`, `routes/`, `resources/`, or `tests/`.

## Setup

| Command | What it does |
| --- | --- |
| `molly:doctor [--workspace=PATH]` | Checks the database, Pest, Git, whether the workspace is a Git repository with a commit (`git_repository` or `workspace_not_git`), the sandbox, parallel checks, the agent, whether an installed Ollama model fits in the memory `molly:preflight` measures (`model_fits_memory`, `model_loaded`, `model_exceeds_memory`, or `model_memory_unknown`), Clever, and the Jev gate. A check it cannot measure has the status `unknown` and does not fail doctor. A `--workspace` that is not an existing directory fails with `WORKSPACE_INVALID` before any check runs. |
| `molly:preflight [--destination=PATH] [--snapshot=FILE]` | Measures this Mac: architecture, Rosetta translation, macOS version, memory and memory pressure, Metal, the installed Ollama runtime and models, and free space on the volume that holds the models. Facts it cannot read are listed as unknown. Then it decides which approved Ollama model fits: `recommended_fit`, `minimum_fit`, `already_installed`, `no_fit`, `unsupported`, or `unknown`. It downloads nothing. It exits `0` whatever the decision, and `1` with `PREFLIGHT_FAILED` or `SNAPSHOT_INVALID` when it cannot produce a snapshot or a saved snapshot fails its digest check. With `--json`, the snapshot has a `decision` object keyed to its `snapshot_sha256`. `--snapshot=FILE` decides from a saved `--json` document instead of measuring. Doctor and each Ollama model request reuse its memory and model measurements; see [Memory](configuration.md#memory) and [The fit decision](../ollama-quickstart.md#the-fit-decision). |
| `molly:install-model [MODEL] [--destination=PATH] [--approve]` | Installs an approved Ollama model that fits this Mac, the one preflight selects unless you name another. It shows the download size and destination volume and asks first; without a terminal or with `--json`, it needs `--approve`. It measures again right before the download, verifies the Ollama version and the model digest, and runs a readiness check. Exits `0` only when the status is `ready`. See [Install a model](../ollama-quickstart.md#install-a-model). |
| `molly:setup --agent=ollama --model=NAME` | Saves a local Ollama model. |
| `molly:setup --agent=amp [--no-login]` | Saves Amp as the agent and connects its MCP client. |
| `molly:settings` | Shows global settings from `~/.molly/settings.json`. |
| `molly:settings-set --patch=JSON` | Merges a JSON patch into global settings. |
| `molly:project-init [PATH]` | Adds Molly to an existing Laravel project: Composer, config, migrations, graphs. The project must be in a Git repository whose HEAD commit contains its files; otherwise it fails with `WORKSPACE_NOT_GIT` or `WORKSPACE_REVISION_MISSING` before writing anything. |
| `molly:project-new [PATH]` | Creates a new Laravel project. Molly attaches it only when the HEAD commit already contains files under the target. |
| `molly:projects` | Lists projects in the shared registry Bloom also reads. |
| `molly:status [--workspace=PATH]` | Shows readiness, running and pending tasks with lease expiry, the Molly worker, and effective settings. Reads only. |

`project-init` and `project-new` take `--no-composer`, `--no-migrate`, and `--no-graphs` to skip steps. Molly writes `.molly/project.json` and adds the project to the registry only after every step succeeds. If the Laravel graph cannot be built, the command exits 1 and the project is not listed. If `composer config` or `composer require` fails, for example with `COMPOSER_REQUIRE_FAILED` on a GitHub authentication error, `project-init` puts `composer.json` and `composer.lock` back exactly as they were, so no `repositories` entry is left behind. `project-new` never creates a Git repository or a commit. A fresh `composer create-project` app is not in any commit, so `project-new` stops after creating the app, exits 0 with `"status": "needs_commit"` and `"project": null`, explains why in `reason`, and lists the commands to run in `next`. Outside any repository, `next` is `git init`, `git add -A`, and `git commit` in the new project, then `molly:project-init` for it. Inside an existing repository, such as `backend/` in a monorepo, `next` never includes `git init`: it is `git -C <repository> add <path>` and `git -C <repository> commit`, then `molly:project-init`. When that repository ignores the target, `reason` says to stop ignoring it in that repository first. Nothing is written to `.molly/` or the registry until you run `molly:project-init`. `project-new` attaches Molly right away, with `"status": "created"` and `"reason": null`, only when `--force` replaces a directory the HEAD commit already contains.

An interrupted `project-init`, even one stopped with `kill -9`, leaves no `.molly/project.json` and no registry entry, and running it again finishes the install. It runs `composer require` again unless `vendor/composer/installed.json` lists `sifrious/molly` and the autoload map includes it, keeps an existing `.gitignore` line, `config/molly.php`, and `repositories` entry, and adds the path to `projects.json` once. `project.json`, `projects.json`, and `config/molly.php` are written to a temporary file and renamed into place, so an interrupted write never leaves half of one. An interrupted `project-new` leaves a partial directory; the next run fails with `PROJECT_PATH_NOT_EMPTY` and the path, and `--force` deletes it and starts again. See [An install was interrupted](../troubleshooting.md#an-install-was-interrupted).

## Queue worker

Queued starts from the web interface and MCP need a queue worker. `molly:worker` runs one for you:

| Command | What it does |
| --- | --- |
| `molly:worker start [--workspace=PATH] [--timeout=10]` | Settles tasks a previous worker left running, then starts `php artisan queue:work` on the default connection and queue as its own process group. Refuses when Molly's worker is already running. |
| `molly:worker status [--workspace=PATH]` | Reports `state` (`running`, `stopped`, or `stale`), pid, process group, uptime, queue, and log path. |
| `molly:worker stop [--workspace=PATH] [--timeout=30]` | Sends `SIGTERM` to the worker's process group, then `SIGKILL` after the timeout. |
| `molly:worker restart [--workspace=PATH] [--timeout=30]` | Stops the worker, settles tasks it left running, then starts a new one. |

Each action takes `--orb=ORB` to manage that Orb's worker instead. An Orb's worker runs `queue:work` on the Orb's own queue, `molly-orb-ORB_ID`, so it runs only tasks queued on that Orb, one at a time. Its record and log are `.molly/worker/orb-ORB_ID.json` and `.molly/worker/orb-ORB_ID.log`. `start` and `restart` refuse a revoked Orb with `ORB_REVOKED`.

Molly starts the worker with `setsid` where it exists, as on Linux. Without `setsid`, as on macOS, `/bin/sh` job control puts the worker in its own process group. When neither is available, `start` fails with `WORKER_START_FAILED` and starts nothing, because `stop` could not reach the worker's children. The worker is recorded in `.molly/worker/worker.json` and writes to `.molly/worker/worker.log`. Only your user can read them: the directory has mode `0700` and both files `0600`. A record is `stale` when its process has exited (`process_gone`) or its pid now belongs to a different command or process group (`pid_reused`). Molly reads the process's command from `/proc` on Linux and from `ps` with the `en_US.UTF-8` locale elsewhere, so a worker whose command has spaces or non-ASCII characters is still recognized when Molly runs without a locale, as it does under Bloom. Molly never signals a stale pid; `start` replaces a stale record, and `stop` removes it. Only one `molly:worker` command runs at a time per workspace. Another one waits up to `--timeout` seconds for `.molly/worker/worker.lock` (10 for `start`, 30 for `stop` and `restart`) and then fails with `WORKER_BUSY`. If Molly cannot write `worker.json` or `worker.log`, `start` fails with `WORKER_START_FAILED` and leaves no worker running. The command never prompts. See [Configuration](configuration.md#database-and-queue) for `molly.worker.php_binary`.

Before `start` and `restart` launch the queue worker, they run a recovery check over every task marked `running` in the database. This is how the records become consistent after a worker was killed or the host restarted. The check recovers a task whose lease expired, and a task claimed by a process on this host whose task lock it can take at once, because a live run holds that lock. It never waits for a lock and leaves every other running task alone. Each recovered task becomes `failed`, or `stopped` when a stop was requested, and so does each run it left `running`; [Recovery after a crash](../tasks.md#recovery-after-a-crash) describes the records. One check looks at no more than 50 tasks per pass, oldest first. With `--json`, `start` and `restart` report `recovery` with `status` (`checked` or `failed`), `recovered` (each task's `task_id`, `reference`, `status`, and `reason`), and `limit_reached`. When the check cannot read the database, `status` is `failed`, `error` starts with `WORKER_RECOVERY_FAILED`, and the worker starts anyway, because it also runs your application's own jobs. The check does not run when you start `queue:work` yourself. A crashed task is then recovered when you run `molly:retry TASK`.

With `--json`, `molly:status` returns `workspace`, `checked_at`, `readiness` (the `molly:doctor` report), `tasks` (`scope`, `running`, and `pending`, each task with `worker_id`, `claimed_at`, `heartbeat_at`, `lease_expires_at`, and `lease_expired`), `worker` (the `molly:worker status` report), `graphs` (the [graph freshness](../knowledge-graph.md#stale-graphs) report for the workspace), and `config` (`molly` settings without credentials or prompts, and the `queue` connection, driver, queue, and `retry_after`). Without `--workspace` it lists tasks from every workspace. It exits `0` whenever it can read that state, even when a check failed, and `1` when it cannot.

## Orbs

A local Orb is a registered worker on this machine with its own ID, runtime, model, repository, and approved worktree root. [Execution targets](../execution-targets.md#local-orbs) explains placement, the checks, and the evidence, and [Run tasks on two local Orbs](../execution-targets.md#run-tasks-on-two-local-orbs) walks through two at once.

| Command | What it does |
| --- | --- |
| `molly:orb-register NAME --model=MODEL --worktree-root=PATH [--runtime=ollama] [--repository=PATH]` | Registers an Orb with a new UUID and checks its runtime. `--runtime` is `ollama` or `amp`; an Amp Orb takes no `--model`. `--repository` defaults to the application. Refuses with `ORB_NAME_INVALID`, `ORB_NAME_TAKEN`, `ORB_RUNTIME_INVALID`, `ORB_MODEL_INVALID`, `ORB_REPOSITORY_INVALID`, `ORB_WORKTREE_ROOT_REQUIRED`, or `ORB_WORKTREE_ROOT_INVALID` and saves nothing. |
| `molly:orbs [--check]` | Lists every Orb with its ID, availability (`available`, `busy`, `unhealthy`, or `revoked`), health, runtime and model, capabilities, repository, worktree root, current task, and queue worker. `--check` asks each active Orb's runtime for its health first and records a heartbeat. |
| `molly:orb-revoke ORB [--reason=TEXT]` | Revokes an Orb. It keeps its ID and history and takes no new task. A task queued on it stays pending and loses its place; a running task stops at its next step. |

`molly:start`, `molly:retry`, and `molly:queue` take the same Orb options:

| Option | Meaning |
| --- | --- |
| `--orb=ORB` | Run on this Orb, by name or ID. |
| `--orb-runtime=RUNTIME` | Run on the first idle, healthy Orb by name with this runtime, `ollama` or `amp`. |
| `--orb-model=MODEL` | Run on the first idle, healthy Orb by name with this model. |

The task must live in a linked Git worktree of the Orb's repository under its worktree root. A refused placement exits `1` with an `ORB_` code and changes nothing; [What Molly checks](../execution-targets.md#what-molly-checks) lists the codes.

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
| `molly:lock-test TASK --approve [--file=PATH] [--reason=TEXT]` | Runs a written Pest test once, then locks it, records the RED baseline, and starts the implementation scope. Refuses with `AUTHORED_TEST_BROKEN` and locks nothing when the test cannot run, naming the cause, the affected tests, and the next command. Without `--file`, the scope is the files `molly:story` derived; `--file` replaces them. Prints the files the implementation may change. Run it again to record a new baseline after fixing the test. |
| `molly:pr-body TASK [--close]` | Prints a pull request description after approval. `--close` adds closing language for an imported issue. |
| `molly:comment TASK --approve [--close]` | Posts or updates one GitHub issue comment. |
| `molly:pr-opened TASK --url URL --approve` | Records a pull request a person opened. |
| `molly:merged TASK --sha SHA --approve` | Records a merge a person made. |
| `molly:handoff TASK --from UUID --to UUID --approve` | Saves a handoff envelope for a child Bloom workspace to `.molly/handoffs/HANDOFF_ID.json` and prints it with that path. With `--json`, prints only the envelope. |
| `molly:bloom-contract TASK --workspace-id=… --branch=… --base-sha=…` | Exports the task contract for a Bloom workspace. |
| `molly:review-commit [REF] [--staged]` | Checks a PHP diff for whitespace problems and, with Jev on, asks for a review. |

Molly never opens, comments on, or merges anything without `--approve`, and it never opens or merges a pull request at all. Without `--approve`, these commands refuse and change nothing:

| Command | Refused with |
| --- | --- |
| `molly:approve` | `APPROVAL_UNCONFIRMED` |
| `molly:lock-test` | `TEST_LOCK_UNCONFIRMED` |
| `molly:pr-opened` | `PR_RECORD_UNCONFIRMED` |
| `molly:merged` | `MERGE_RECORD_UNCONFIRMED` |
| `molly:handoff` | `HANDOFF_UNCONFIRMED` |
| `molly:comment` | `GITHUB_WRITEBACK_UNAPPROVED` |

These commands are the only way to record a human decision: the MCP tool refuses the same operations with `HUMAN_APPROVAL_REQUIRED` and returns the command to run. See [Human decisions](../agents.md#human-decisions).

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

```bash
php artisan molly:import https://github.com/OWNER/REPO/issues/123 \
  --name=issue-123 --test=tests/Feature/ExampleTest.php --file=app/Example.php \
  --allow-test-edits --todos
```

With `--todos`, Molly also writes the test file with one `->todo()` per acceptance criterion and saves a test-authoring task. It refuses an existing test file with `TODOS_TEST_EXISTS` and an issue without criteria with `ISSUE_CRITERIA_MISSING`. See [Acceptance criteria to Pest todos](../github-todos.md).

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

## Errors

A failed command prints its error on stderr, so stdout carries only the report. With `--json`, stdout still carries the JSON document with the error in it, and stderr gets one line in the form `CODE: message`. An unknown option or a missing argument fails with `ARGUMENTS_INVALID` in the same way, for the `molly:` and `clever:` commands. An error that has no Molly code is printed as `COMMAND_FAILED: message`.

`molly:create`, `molly:run`, `molly:import`, and `molly:story` check their own inputs before anything else. A missing prompt fails with `PROMPT_REQUIRED`, a missing story with `STORY_REQUIRED`, a missing `--test` with `TEST_REQUIRED`, a test file that does not exist with `PROTECTED_TEST_MISSING`, and a directory that is not a Laravel application with `WORKSPACE_INVALID`. `molly:run` reports these before it refuses a host without a sandbox, and `molly:import` reports them before it asks GitHub for the issue.

Some errors ask you to choose a value: `TEST_PROTECTED` and `SCOPE_REQUIRED` (files the task may change), `TEST_PATH_INVALID` (the Pest test), and `COMMIT_REF_INVALID` (a commit to review). On a terminal without `--json`, `molly:create`, `molly:run`, `molly:import`, `molly:story`, `molly:lock-test`, and `molly:review-commit` offer the choices with a prompt and continue with your answer. With `--json` or `--no-interaction`, the message lists the choices and ends with `Run: COMMAND`, and the JSON document adds `choices`, a list of `{value, label}` objects, and `rerun`, the command with the first choice filled in. MCP tools and `GUIDE_SOURCE_NOT_FOUND` list the choices in the message.

After a test-authoring run, `molly:start`, `molly:retry`, and `molly:task` print the authored test check and the next command. With `--json`, the run report holds the check in `report.authored_test`, `molly:task` adds `authored_test`, and all three add `next`, an object with `command` and `reason`. `molly:lock-test` fails with `AUTHORED_TEST_BROKEN` when the test cannot run; its JSON document adds `authored_test` and `next`. See [Troubleshooting](../troubleshooting.md#the-authored-test-cannot-run).

`molly:story` fails with `SCOPE_EMPTY` when the model names no file the implementation may change, and saves no task. `molly:lock-test` fails with `SCOPE_REQUIRED` when the task has no derived files and no `--file` was given; the message includes a command to run. See [Troubleshooting](../troubleshooting.md#the-story-has-no-implementation-files).

`molly:check INPUT OUTPUT` is internal to the parallel check runner. It takes no `--json` flag; it reads its input file and writes its result to the output file. Do not call it.

## Related

- [Tasks](../tasks.md)
- [Configuration](configuration.md)
- [Troubleshooting](../troubleshooting.md)

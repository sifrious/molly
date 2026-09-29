# Execution targets

A Molly run executes on one of two targets. The local target is the machine where you run Artisan or the queue worker. A local Orb is a named worker on the same machine that you register: it has its own ID, one runtime and model, one repository it may change, and an approved directory that holds a Git worktree for each of its tasks. Each Orb runs one task at a time, so two Orbs can run two tasks at once in separate worktrees.

Hosted Orbs, which would run on another machine, are not shipped. [Hosted Orbs](#hosted-orbs) says what that follow-up must prove.

## Local execution

Nothing needs to be configured. `molly:start`, `molly:retry`, a queued start from the web interface or MCP, and `molly:worker` all run the writer, Pest, and the Tarpit review on the machine that runs the command or the queue worker.

Each run records where its checks ran. Start a task and read the run:

```bash
php artisan molly:start ready-check
php artisan molly:show RUN_ID
```

With the default parallel checks, the report has one row per check, and the `Target` column says `local` for both. Abbreviated, without the table borders:

```text
Execution mode: parallel
Branch        Status  Target  Provider / model
verification  passed  local   Pest
review        passed  local   ollama / gpt-oss:20b
```

The same value is saved in the run report as `branches[].execution_target`, so `molly:show RUN_ID --json` shows it too, and the web run page lists it under "Execution branch details". With `molly.parallel_checks` set to `false`, Pest and the review run one after the other inside the Artisan process and record no branches. The report then says `Execution mode: serial` and `No branch results recorded.`, and has no `Target` column. The checks still run on the local machine.

Local execution uses the sandbox the host provides. On Linux with Landlock and user namespaces the writer and Pest run isolated; on macOS they need the override described in [macOS and the sandbox](getting-started.md#macos-and-the-sandbox).

Claims of the same task are coordinated through a lease in the application database. Two workers racing for the same task produce one claim; the other sees `WORKSPACE_BUSY`. An expired lease is recovered into a failed, retryable task without any coordinator. A queued start or retry is delivered once per task attempt, so a duplicate job cannot succeed twice.

## Local Orbs

An Orb record lives in the `molly_orbs` table of the application database. Its UUID is its identity. The name, such as `big`, is a label you type; a path, a process ID, or a queue name never identifies an Orb. Each Orb records:

| Field | Meaning |
| --- | --- |
| `id` | The Orb's UUID, set once at registration. |
| `name` | A unique label: lowercase letters, numbers, and hyphens. |
| `device` | The host name of the machine that registered it. |
| `runtime`, `model` | `ollama` and an installed Ollama model, or `amp`, where Amp chooses the model. |
| `repository` | The Git checkout the Orb may change, with its Git common directory. |
| `worktree_root` | The approved directory that holds the Orb's task worktrees. |
| `health`, `health_reason`, `heartbeat_at` | The result and time of the last runtime check. |
| `current_task` | The task the Orb holds, or none. |
| `revoked_at` | When the Orb was revoked, if it was. |

`molly:orbs` reports each Orb's availability: `available` (healthy and free), `busy` (holding a task), `unhealthy` (the last runtime check failed), or `revoked`.

### Register an Orb

```bash
php artisan molly:orb-register big --model=gpt-oss:120b-code --worktree-root=../orbs
```

`--runtime` defaults to `ollama`, and `--repository` defaults to the application. The worktree root must be an existing directory you can write, outside the repository's `.git` directory. Molly resolves both paths to their canonical form and saves them with the Orb.

Registration checks the runtime at once. An Ollama Orb is healthy when Ollama answers on the loopback URL in `ai.providers.ollama.url` and `/api/tags` lists the model; Molly also records the Ollama version and the model digest. An Amp Orb is healthy when `amp usage` succeeds. An unhealthy Orb is still registered, and takes no task until it passes. `php artisan molly:orbs --check` runs the check again for every active Orb and records a new heartbeat.

### Place a task on an Orb

A task placed on an Orb must live in its own linked Git worktree of the Orb's repository, under the Orb's worktree root. Create the worktree with `git worktree add`, then save the task there with `molly:create --workspace`.

Name an Orb, or require a runtime or model and let Molly choose:

```bash
php artisan molly:queue ready --orb=big
php artisan molly:queue greeting --orb-model=gpt-oss:20b
php artisan molly:start ready --orb=big
php artisan molly:retry ready --orb-runtime=ollama
```

`molly:queue` sends the start, or the retry with `--retry`, to the Orb's own queue, `molly-orb-ORB_ID`, and returns. While a start or retry of the task is still queued or running, `molly:queue` adds no second job and reports `already_queued` instead of `queued`; the Orb that holds the queued job keeps it, and an Orb this call reserved for nothing is freed. `molly:start` and `molly:retry` run the attempt in your terminal. MCP `molly_task` takes the same choice for `start` and `retry` as `orb`, `orb_runtime`, and `orb_model`.

With `--orb-runtime` or `--orb-model`, Molly goes through the active Orbs that match, in order of name and then ID, and takes the first one that passes every check below. The same records always give the same choice. When none passes, the error is `ORB_UNAVAILABLE` and lists each Orb's refusal.

### What Molly checks

Molly checks the placement when it queues the task, and again when the attempt starts. Right before the first write, which is the baseline restore of a retry, it resolves the paths again and compares them with the saved ones.

| Code | Why the Orb refused |
| --- | --- |
| `ORB_NOT_FOUND` | No registered Orb has that name or ID. `molly:start` and `molly:retry` check the name before Git and the sandbox. |
| `ORB_REVOKED` | The Orb was revoked. |
| `ORB_BUSY` | The Orb holds another task. |
| `ORB_CAPABILITY_MISMATCH` | The Orb does not have the required runtime or model. |
| `ORB_WORKTREE_ROOT_INVALID` | The approved root is missing or now resolves to another path. |
| `ORB_WORKTREE_INVALID` | The task's worktree is missing or now resolves to another path, for example through a symbolic link. |
| `ORB_WORKTREE_OUTSIDE_ROOT` | The task's workspace is not under the Orb's worktree root. |
| `ORB_REPOSITORY_NOT_GRANTED` | The workspace is not a worktree of the Orb's repository. |
| `ORB_WORKTREE_NOT_LINKED` | The workspace is the repository's main checkout, not a linked worktree. |
| `ORB_TASK_PLACED` | The task is already placed on another Orb. |
| `ORB_WORKTREE_OCCUPIED` | Another task is running in the same worktree, or is placed on an Orb there. |
| `ORB_UNHEALTHY` | The runtime check failed. The message includes the reason, such as `ORB_MODEL_MISSING`. |
| `ORB_PROMPT_CHANGED` | The task's prompt changed after placement. Molly launches only the prompt it placed. |
| `ORB_TASK_REQUIRED` | The request did not name a saved task. |

A refused start leaves the task as it was, frees the Orb it placed or named, and appends a `start_refused` event with the code to the task's `.molly/lifecycle.jsonl`.

### One task per Orb

Placing a task reserves the Orb for it. The reservation is taken with one conditional database update, and again inside the claim transaction, so two tasks cannot both hold one Orb. The workspace lock in each worktree's `.molly/` directory still refuses a second run in the same worktree with `WORKSPACE_BUSY`.

The Orb is free again when the attempt ends, when a queued task is stopped with `molly:stop`, when recovery settles a task a killed worker left running, and when a queued task's Orb is revoked. A failed or stopped task holds an Orb only while a retry is queued on it.

### When an Orb run does not finish

Orb runs use the same lease, claim, recovery, and duplicate-delivery rules as local runs.

| Event | Result |
| --- | --- |
| The worker is killed, or the host restarts | The next `molly:worker start` or `restart`, `molly:retry`, or an expired lease settles the task as `failed` and retryable. The run gets `RUN_ABANDONED` and a `recovery` entry, and the Orb is free. |
| A step times out | A model request past `molly.timeout` or a Pest run past `molly.test_timeout` fails the run, as it does locally. The Orb is free. |
| `molly:stop TASK` | A queued task becomes `stopped` and frees the Orb. A running task stops at its next step and is saved as `stopped`. |
| The same start is delivered twice | One job runs the attempt. The other fails with `WORKSPACE_BUSY` or `TASK_NOT_PENDING` and writes nothing. |
| `molly:orb-revoke ORB` | The Orb takes no new task. A task queued on it stays `pending` and loses its place. A running task stops at its next step, with a `stop_reason` that starts with `ORB_REVOKED`. |

### What each attempt records

A run on an Orb saves `report.execution_target`, which `molly:show RUN_ID --json` prints. It holds the target snapshot (`kind` `orb`, `target_id`, `capabilities`, `provider` `local_orb`, and `selection_reason`), the Orb (`id`, `name`, `device`, `runtime`, `model`, `health`, and `runtime_identity` with the Ollama version and model digest), the `repository`, the canonical `worktree` and `worktree_root`, `prompt_sha256`, `placed_at`, `starting_revision`, `started_at`, `finished_at`, and `result`. `diff` names a patch saved beside the run's evidence under `storage/molly/RUN_ID/worktree.patch`, with its size and SHA-256 digest. The patch compares the starting revision with the worktree for the task's files.

The run's `model`, `model_identity`, and `effective_config` name the Orb's model, and each parallel check lists the Orb's ID as its `execution_target`. Each verification receipt saves the same `execution_target` in its `context`, and the receipt's evidence digest covers it. In `.molly/lifecycle.jsonl`, `dispatch_requested` names the Orb, runtime, model, worktree, and starting revision, and the event that ends the attempt names the Orb, the result, and the diff digest.

`molly:show` prints an `Execution target:` line and the diff, `molly:task` adds a `Target` column to its runs, and `molly:receipt` names the Orb under each receipt.

## Run tasks on two local Orbs

This tutorial registers two Ollama Orbs, queues one task on each, watches both run at once in separate worktrees, and reads each run's evidence after a worker restart. It uses the application from [Getting started](getting-started.md): `app/Greeting.php` and `tests/Feature/GreetingTest.php` from `molly:demo`, and `tests/Feature/ReadyTest.php` from [Create your own task](getting-started.md#create-your-own-task). Both tests must fail when you start.

You need both models in Ollama:

```bash
ollama pull gpt-oss:120b-code
ollama pull gpt-oss:20b
```

Queued starts need a queue with a reservation time above 3600 seconds. This tutorial runs two workers at once, so on a SQLite queue it needs PHP 8.4 or later and `transaction_mode` set to `IMMEDIATE`. On PHP 8.3 the second `molly:worker start` fails with `WORKER_CONCURRENCY_UNSUPPORTED`; use a MySQL or PostgreSQL queue database there. [Queue requirements](web-interface.md#queue-requirements) has the settings. On macOS, the writer and Pest also need the override in [macOS and the sandbox](getting-started.md#macos-and-the-sandbox).

### Give each task a worktree

Every worktree starts from a commit, so commit the files the tasks need:

```bash
git add app/Greeting.php tests/Feature/GreetingTest.php tests/Feature/ReadyTest.php
git commit -m 'Add the greeting and ready tests'
```

Create the approved root and one worktree per task, each on its own branch. Pest runs in each worktree, so each needs its own `vendor` directory and `.env`:

```bash
mkdir ../orbs
git worktree add -b orb-greeting ../orbs/greeting
git worktree add -b orb-ready ../orbs/ready
composer install --working-dir=../orbs/greeting
composer install --working-dir=../orbs/ready
cp .env ../orbs/greeting/.env
cp .env ../orbs/ready/.env
```

Save one task in each worktree. The two tasks change different files:

```bash
php artisan molly:create 'Return Hello from the greeting helper.' \
  --workspace=../orbs/greeting --name=orb-greeting \
  --test=tests/Feature/GreetingTest.php --file=app/Greeting.php

php artisan molly:create 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.' \
  --workspace=../orbs/ready --name=orb-ready \
  --test=tests/Feature/ReadyTest.php --file=routes/web.php
```

### Register two Orbs

```bash
php artisan molly:orb-register big --model=gpt-oss:120b-code --worktree-root=../orbs
php artisan molly:orb-register small --model=gpt-oss:20b --worktree-root=../orbs
php artisan molly:orbs
```

Both Orbs are healthy and free. Abbreviated, without the table borders:

```text
Orb    Availability  Health   Runtime / model             Current task  Worker
big    available     healthy  ollama / gpt-oss:120b-code  idle          stopped
small  available     healthy  ollama / gpt-oss:20b        idle          stopped
```

Below the table, `molly:orbs` prints each Orb's ID, repository, and worktree root.

### Run both tasks at once

Start one queue worker per Orb. Each reads only its Orb's queue:

```bash
php artisan molly:worker start --orb=big
php artisan molly:worker start --orb=small
```

Queue one task by Orb name and the other by model:

```bash
php artisan molly:queue orb-greeting --orb=big
php artisan molly:queue orb-ready --orb-model=gpt-oss:20b
```

```text
Queued task orb-greeting on Orb big (ollama / gpt-oss:120b-code). php artisan molly:worker start --orb=big runs it.
Queued task orb-ready on Orb small (ollama / gpt-oss:20b). php artisan molly:worker start --orb=small runs it.
```

While the models work, both Orbs are busy:

```bash
php artisan molly:orbs
```

```text
Orb    Availability  Health   Runtime / model             Current task            Worker
big    busy          healthy  ollama / gpt-oss:120b-code  orb-greeting (running)  running (pid 48213)
small  busy          healthy  ollama / gpt-oss:20b        orb-ready (running)     running (pid 48240)
```

A third task cannot take a busy Orb. The `demo-greeting` task from Getting started is still pending:

```bash
php artisan molly:queue demo-greeting --orb=big
```

```text
ORB_BUSY: Orb big is working on task orb-greeting. Each Orb takes one task at a time. Wait for that task to finish, or choose another Orb.
```

The command exits `1` and queues nothing.

### Inspect the results after a restart

When `php artisan molly:tasks` shows both tasks as `completed` or `failed`, restart one worker. The restart settles any task the old worker left running, then starts a new process:

```bash
php artisan molly:worker restart --orb=big
php artisan molly:orbs
```

Both Orbs are `available` again. The saved records do not depend on the workers, so each attempt reads the same after the restart:

```bash
php artisan molly:task orb-ready
php artisan molly:show RUN_ID
php artisan molly:receipt RUN_ID
```

`molly:task` lists the run with `orb small` in its `Target` column. `molly:show` names the Orb, its model, the worktree, the starting revision, and the patch:

```text
Execution target: Orb small (01a0eb04-5728-711f-821f-0a0c4f86300c), ollama / gpt-oss:20b, worktree /Users/you/orbs/ready, starting revision 984061c15f533b624454204ed7a783506b76aeef.
Worktree diff: /Users/you/app/storage/molly/RUN_ID/worktree.patch (161 bytes, sha256 e2fa7aed2e83426be641e163de2ca4b9b08103f699e0015c1b5820a0461de35f).
```

Its branch table shows `orb small` as the target of both checks. `molly:show RUN_ID --json` prints the whole `execution_target`, and `molly:receipt` names the Orb under each receipt.

### Review and clean up

Review each branch as you would any other. `git -C ../orbs/ready diff` shows the change, and `vendor/bin/pest` in the worktree runs its full suite. Molly does not commit or merge. When you are done:

```bash
php artisan molly:worker stop --orb=big
php artisan molly:worker stop --orb=small
git worktree remove ../orbs/greeting
git worktree remove ../orbs/ready
```

To retire an Orb, run `php artisan molly:orb-revoke big`. A revoked Orb keeps its ID and history, and its name stays taken.

To add another Orb later, register it with a new name and start its worker. Each Orb runs one task at a time, so the number of healthy Orbs with running workers is the number of tasks that run at once.

## Amp threads are not Orbs

Molly can save a link between a task and an Amp thread and read Amp's reported connection state:

```bash
php artisan molly:link-thread TASK THREAD_ID
php artisan molly:connections TASK
php artisan molly:connections TASK --stored --json
```

These commands record user-supplied thread links and read connection evidence. They do not start remote work, and a linked thread does not change where a run executes. A thread ID is not an Orb ID: `--orb=T-...` fails with `ORB_NOT_FOUND`. [Task connections](connections.md) covers these commands.

## Hosted Orbs

> This section is a design note for maintainers. Hosted Orbs are not shipped and are not promised for a release.

`Sifrious\Molly\Execution\SelectExecutionTarget` is the one place Molly decides where a task runs. With no request it selects the local machine; an Orb request goes to `Sifrious\Molly\Execution\LocalOrbProvider`. A hosted provider for Orbs on other machines would join at the same seam and return the same `molly.execution_target_snapshot.v1` snapshot.

A hosted provider needs a protocol that can prove:

- a stable remote executor identity across reconnects
- fresh connection state
- task assignment and release
- capabilities and workspace access
- cancellation results
- trustworthy event timestamps

A thread ID, display name, socket, process ID, or raw executor type is not enough on its own. Presence and assignment are different facts: a connected executor is not automatically available, and reconnecting should not silently restore an old task assignment.

A real connector should be tested against reconnects, delayed events, stale observations, explicit disconnects, task renames, and preserved association history. The task contract's `approval.before_remote_dispatch` stays `true` for that work. Local Orbs run on this machine and do not use it.

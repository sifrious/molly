# Execution targets

Every Molly run executes on the machine where you run Artisan. That machine is the local execution target, and it is the only target Molly supports. Remote execution on an Orb is not shipped, and Molly refuses every Orb request. Whether the alpha waits for Orb execution or ships without it is a release-scope decision that has not been made; [The Orb requirement is open](#the-orb-requirement-is-open) says what closes it.

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

## Orb requests are refused

No Artisan command, MCP tool, or web page asks for an Orb. The only way to request one is in PHP, through `Sifrious\Molly\Execution\SelectExecutionTarget`. From `php artisan tinker` in your application:

```php
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Execution\SelectExecutionTarget;

app(SelectExecutionTarget::class)->handle(new ExecutionTargetRequest(ExecutionTargetKind::Orb, 'orb-build-1'));
```

Molly refuses every Orb request, whatever target it names:

```text
RuntimeException ORB_UNVERIFIED: This Molly release runs tasks only on the local machine. An Amp thread or connected executor is not a verified Orb. Select local execution.
```

An Orb request without a target fails earlier, when the request is built, with `CONTRACT_FIELD_INVALID: An Orb execution target needs a target_id.` Calling `handle()` with no request selects local execution and returns a snapshot with `kind` and `target_id` set to `local`. `tests/Feature/SelectExecutionTargetTest.php` makes each of these calls.

## The Orb requirement is open

MME-5211 lists "Use local/Orb execution targeting" as an alpha tutorial requirement. This page documents the local half. The Orb half cannot be documented as a working tutorial, because Molly has no remote dispatch, so the requirement stays open. `python3 bin/molly-docs-check --release` reports it, and `bin/molly-release-gates` stops on it, until one of these is recorded:

- Verified Orb dispatch ships with a runnable tutorial and execution-target evidence. [Planned remote execution](#planned-remote-execution) lists what that work must prove.
- A release-scope decision moves Orb execution out of the alpha.

[Work packages](work-packages.md) tracks the same follow-up as MOL-WP-07.

## Amp threads are not Orbs

Molly can save a link between a task and an Amp thread and read Amp's reported connection state:

```bash
php artisan molly:link-thread TASK THREAD_ID
php artisan molly:connections TASK
php artisan molly:connections TASK --stored --json
```

These commands record user-supplied thread links and read connection evidence. They do not start remote work, and a linked thread does not change where a run executes. [Task connections](connections.md) covers them.

## Planned remote execution

> This section is a design note for maintainers. None of these controls exist, and none are promised for a release.

### What is still planned

Future work needs a provider protocol that can prove:

- a stable remote executor identity across reconnects
- fresh connection state
- task assignment and release
- capabilities and workspace access
- cancellation results
- trustworthy event timestamps

A thread ID, display name, socket, process ID, or raw executor type is not enough on its own.

### Presence and assignment are different

Future code should keep these facts separate:

- who the remote executor is
- whether it is currently reachable
- whether it is available
- which task it is assigned to
- where one specific run actually executed

A connected executor is not automatically available. Reconnecting should not silently restore an old task assignment.

### Proposed user operations

These names are examples for future work. They are not registered commands today.

| Planned operation | Example CLI |
| --- | --- |
| Find verified remote executors for a task | `molly:orbs --task TASK` |
| Connect | `molly:orb:connect CONNECTION` |
| Refresh | `molly:orb:refresh ORB_ID` |
| Disconnect | `molly:orb:disconnect ORB_ID` |

Before any future dispatch, Molly should recheck identity, connection, required capabilities, workspace access, and capacity. The selection reason and actual execution target should be recorded as evidence.

### Verification needed before shipping

A real connector should be tested against reconnects, delayed events, stale observations, explicit disconnects, task renames, and preserved association history.

CLI, MCP, and web should report the same underlying evidence once those interfaces exist.

Until that work lands, do not describe current Amp thread observations as verified Orb execution.

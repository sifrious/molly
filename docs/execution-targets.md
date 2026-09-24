---
layout: default
title: Execution targets (planned)
---

# Execution targets (planned)

> This is a design note for maintainers. None of the Orb or remote execution controls on this page exist yet. Every Molly run executes on the machine where you run Artisan.

Today, Molly can save an Amp thread association and read Amp's reported executor connection state. Local execution is the default. Molly refuses an Orb request unless a capability-checked target is supplied. An Amp thread ID is not that target.

For current behavior, read [Task connections](connections.md).

## What works today

```bash
php artisan molly:link-thread TASK THREAD_ID
php artisan molly:connections TASK
php artisan molly:connections TASK --stored --json
```

These commands record user-supplied thread links and read connection evidence. They do not start remote work.

## What is still planned

Future work needs a provider protocol that can prove:

- a stable remote executor identity across reconnects
- fresh connection state
- task assignment and release
- capabilities and workspace access
- cancellation results
- trustworthy event timestamps

A thread ID, display name, socket, process ID, or raw executor type is not enough on its own.

## Presence and assignment are different

Future code should keep these facts separate:

- who the remote executor is
- whether it is currently reachable
- whether it is available
- which task it is assigned to
- where one specific run actually executed

A connected executor is not automatically available. Reconnecting should not silently restore an old task assignment.

## Proposed user operations

These names are examples for future work. They are not registered commands today.

| Planned operation | Example CLI |
| --- | --- |
| Find verified remote executors for a task | `molly:orbs --task TASK` |
| Connect | `molly:orb:connect CONNECTION` |
| Refresh | `molly:orb:refresh ORB_ID` |
| Disconnect | `molly:orb:disconnect ORB_ID` |

Before any future dispatch, Molly should recheck identity, connection, required capabilities, workspace access, and capacity. The selection reason and actual execution target should be recorded as evidence.

## Verification needed before shipping

A real connector should be tested against reconnects, delayed events, stale observations, explicit disconnects, task renames, and preserved association history.

CLI, MCP, and web should report the same underlying evidence once those interfaces exist.

Until that work lands, do not describe current Amp thread observations as verified Orb execution.

## What Molly does today

Molly claims a task through a lease in the application database. Two workers racing for the same task produce one claim; the other sees `WORKSPACE_BUSY`. An expired lease is recovered into a failed, retryable task without any coordinator. A queued start or retry is delivered once per task attempt, so a duplicate job cannot succeed twice.

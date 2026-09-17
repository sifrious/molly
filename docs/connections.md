---
layout: default
title: Task connections
---

# Task connections

Use this feature to record that a saved Molly task is associated with an Amp thread, then optionally ask Amp for current executor connection evidence.

This does not start work and does not prove Orb identity.

## Link a thread

Copy the `T-...` thread ID from Amp, then run:

```bash
php artisan molly:link-thread health-check T-00000000-0000-0000-0000-000000000000
```

`health-check` can be a task nickname or UUID.

Linking stores your assertion. Molly validates the ID format but does not contact Amp to prove the thread exists.

## Read saved links only

```bash
php artisan molly:connections health-check --stored
```

This never contacts Amp.

A saved association can be:

- `latest_recorded`: the thread's latest saved link names this task.
- `prior_exact`: this thread was linked to this task before, but its latest saved link names another task.

Neither value proves current execution.

## Ask Amp for current connection evidence

```bash
php artisan molly:connections health-check
php artisan molly:connections health-check --json
```

The host process must be able to run an authenticated `amp` executable.

Connection values mean:

| Value | Meaning |
| --- | --- |
| `connected` | A fresh Amp snapshot reports a connected executor for the thread. |
| `disconnected` | A fresh Amp snapshot explicitly reports no connected executor. |
| `unknown` | Molly does not have reliable current evidence. |

The raw `executor_type` may also be present. Molly preserves it without turning it into verified Orb identity.

`working` is a separate observed value. Even `working=true` does not prove that the executor is working on this Molly task.

## Web and MCP

The local web task page has a **Find linked Amp threads** flow.

MCP clients can:

- call `molly_connections` to read associations and optional Amp observations
- call `molly_task` with `operation: "link_thread"` to save an association

## Current limits

- Connection observations are not persisted as task history.
- A successful command exit does not mean an executor is connected. Inspect the returned status and matches.
- The current lookup does not verify Orb identity.
- Molly does not connect, disconnect, or select remote execution targets.

Those remote execution features are planned separately in [Execution targets](execution-targets.md).

## Next

- [Agents and MCP](agents.md)
- [Execution targets, planned work](execution-targets.md)

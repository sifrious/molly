---
layout: default
title: Planned remote execution
---

# Planned remote execution

> This page is a maintainer design note. The Orb and remote execution controls described here are not shipped user features.

Today, Molly can save an Amp thread association and read Amp's reported executor connection state. It cannot verify an Orb identity, connect or disconnect an Orb, or choose a remote execution target.

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

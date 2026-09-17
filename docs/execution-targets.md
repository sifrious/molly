---
layout: default
title: Plan Orb connections and execution targets
---

# Plan Orb connections and execution targets

Molly can now record an Amp thread against a saved task and read the thread's executor connection state. Follow [task connections](connections.md) to use the CLI, MCP, and web controls.

Orb identification, Orb connection management over MCP, Bloom workspace ownership, and remote execution selection remain planned. Molly already exposes [planning and task tools over local MCP](agents.md#mcp-tools). A connected Amp executor does not establish an Orb identity or prove that the executor is working on the linked Molly task.

## What works today

`src/Actions/LinkTaskThread.php` stores a user-recorded association between an Amp thread ID and a canonical Molly task UUID. `src/Actions/FindTaskConnections.php` combines exact task history with the read-only observations from `src/Actions/ReadAmpConnections.php`. CLI, local web, and MCP handlers call those actions. The implemented MCP tools are `molly_connections` for lookup and the `molly_task` operation `link_thread` for recording an association.

The current commands are:

```bash
php artisan molly:link-thread TASK THREAD_ID
php artisan molly:connections TASK
php artisan molly:connections TASK --stored --json
```

`TASK` accepts a nickname or UUID. `THREAD_ID` is an Amp thread ID beginning with `T-` followed by a UUID. Linking records the user's assertion. The link operation does not contact Amp to verify that the thread exists. A live lookup reads Amp metadata without sending a prompt or starting work.

Current matching distinguishes the latest recorded task from a prior exact task association. The latest record is not a provider-confirmed assignment. A task rename preserves the UUID and association history. The [connection guide](connections.md#read-task-associations) describes the limits and display order.

## Establish a real Orb identity

The next connector must identify an Orb through a supported provider protocol. A thread ID, executor connection flag, display name, socket address, or process ID alone does not meet that requirement. Preserve raw provider metadata without treating an undocumented executor type as proof of a remote Orb.

Verify these facts before adding connection controls:

- How the provider authenticates an Orb and supplies a stable identity.
- How discovery distinguishes the same Orb reconnecting from a different Orb.
- Which events confirm connection, disconnection, task assignment, release, and run or branch identity.
- Which event timestamps are trustworthy and how the provider handles delayed events.
- Which capabilities, workspace permissions, and capacity measurements the provider can report.
- What disconnecting does to active work and whether cancellation has a confirmed result.

Keep the provider boundary small. Reuse the host application's database and queues. Add an interface when a real connector needs the boundary; do not create a separate registry service or worker framework for hypothetical providers.

## Keep presence and assignment separate

The future connector must distinguish Orb identity, a particular connection, and task association history. Stable identity must survive reconnects. An older connection event must not reopen an assignment or mark a newer connection online.

Use `online` only when fresh identity and liveness evidence support the result. Use `offline` only for an explicit disconnect or connection-closed result. A missing heartbeat, failed check, or stale record means `unknown`. Set freshness rules from the confirmed protocol rather than applying an arbitrary heartbeat interval.

Availability is another fact. An online Orb may be busy, and a reconnect does not restore a previous task as the current assignment. Require fresh assignment evidence before claiming current work on a task. Preserve the provider event time, Molly's check time, and the evidence behind each conclusion.

The current Amp lookup uses the narrower terms `connected`, `disconnected`, and `unknown`. Those terms describe observed executor state. They must not silently become Orb presence or execution eligibility.

## Find the Orb associated with a task

Provider-confirmed lookup is planned. Resolve a task nickname to the canonical UUID, then distinguish confirmed current assignments from prior exact assignments. A newer association with another task must not erase history for the requested task.

Show connected current exact matches first. Show connected prior exact matches next, ordered by the supported association timestamp. Keep offline and unknown history available for inspection. Preserve equally ranked candidates and their evidence. Stable display ordering must not select an execution target or imply a recommendation.

Return the matching reason, task UUID, Orb identity, association source and time, connection evidence, and related run or branch identifiers when available. Similar titles, prompts, or repositories do not establish an exact association. Exact matching needs no model.

The implemented `latest_recorded` and `prior_exact` labels describe user-recorded thread links. Keep those labels distinct from any later provider-confirmed assignment.

## Expose one capability through CLI, MCP, and web

The following operations remain proposed. None of these Orb commands or MCP tools are registered today.

| Operation | Proposed CLI | Proposed MCP tool | Proposed local web flow |
| --- | --- | --- | --- |
| Find associated Orbs | `molly:orbs --task health-check` | `molly_orbs_list` | Read verified Orb identity, presence, and exact task associations. |
| Connect | `molly:orb:connect CONNECTION` | `molly_orb_connect` | Submit a connection form and read the confirmed result. |
| Refresh | `molly:orb:refresh ORB_ID` | `molly_orb_refresh` | Check the connection and show the evidence and check time. |
| Disconnect | `molly:orb:disconnect ORB_ID` | `molly_orb_disconnect` | Close the connection and preserve association history. |

`CONNECTION` is a placeholder until the selected protocol defines discovery and authentication. Do not expose credentials in reports. Molly exposing an MCP tool does not mean that the Orb itself uses MCP.

Keep transport handlers thin. Each interface must call the same application action and return the same identifiers, states, reasons, and timestamps. Continue using Laravel Prompts for CLI reports and complete server-rendered HTML with native forms for the web interface. Lookup must not connect a new target, send work, or choose a target implicitly.

Starting remote work is a separate capability. Before dispatch, recheck the chosen target's connection, required capabilities, workspace access, and capacity. Record the requested requirements, eligible targets, chosen target, selection reason, actual target, and any fallback reason. Current Molly execution remains local.

## Verify and publish each implemented step

First verify a real provider protocol and capture fixtures for the facts that protocol supplies. Then test identity across reconnects, duplicate and delayed events, stale evidence, explicit disconnection, task renames, and preserved association history. Verify ordering and ties without automatic target selection.

Compare CLI, MCP, and web results for the same evidence when each interface exists. Test native forms, visible state text, keyboard access, and operation without JavaScript. Run a real connector acceptance check before publishing a support claim.

Move completed behavior into the [connection guide](connections.md) and [command reference](reference/commands.md). Keep unsupported operations marked as planned. The [publishing process](publishing.md) deploys documentation to GitHub Pages; the planned `mary.win` documentation subdomain is not an Orb endpoint. Connections and execution remain in the host application.

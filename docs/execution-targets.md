---
layout: default
title: Plan Orb connections and execution targets
---

# Plan Orb connections and execution targets

This is a proposed design. Molly does not yet connect to Orbs, expose Orb tools over MCP, or select remote execution targets. The commands and flows on this page are implementation targets, not commands you can run today.

The first goal is to answer a concrete question. Which connected Orb is working on this task, or most recently worked on this exact task? Show the evidence before asking the user to choose an execution target.

## One capability, three interfaces

Keep connection management and task lookup in Molly's application actions. The CLI, MCP tools, and local web interface call those same actions and read the same stored records. Do not create separate CLI, MCP, and web registries or duplicate the matching rules.

Start with one real Orb connector and a local protocol fixture for tests. Choose the connector after confirming the actual Orb protocol. Add an interface only if a real provider boundary needs one; do not build a general worker framework for imagined connectors.

The proposed actions are small:

| Action | Responsibility |
| --- | --- |
| `ConnectOrb` | Establish a connection through the selected connector, verify the Orb's identity, and record the connection result. |
| `RefreshOrbConnection` | Read current connection evidence, record heartbeats or a failed check, and report the observed state. |
| `DisconnectOrb` | Close the connection and record the outcome. Preserve the Orb and its association history. |
| `RecordOrbAssociation` | Record a confirmed task, run, or branch association and its source and timestamps. Close an association when the connector confirms release. |
| `FindTaskOrbs` | Resolve a task reference, read current presence and exact association history, and return candidates with reasons and ties. |

These names are proposed application actions. No classes or tables are added by this plan. Use the host application's existing persistence and queue facilities where needed. Do not add a separate registry service, message broker, or event bus for discovery.

## Keep identity, presence, and history distinct

An Orb needs a stable identity that survives reconnects. A display name, socket address, or process ID is not sufficient. Confirm whether the real protocol supplies an authenticated stable ID. If the protocol does not, define a verified pairing procedure before calling two connections the same Orb.

Keep the following information in the host application:

| Record | Information to preserve |
| --- | --- |
| Orb identity | Stable ID, display label, connector type, and verified identity evidence. Display labels may change without replacing the identity. |
| Connection | Orb ID, connection instance, opened time, last confirmed heartbeat, last check time, disconnected time when known, state, and failure reason. |
| Task association | Orb ID, task UUID, connection instance, start and end times, association source, and related run UUID or branch ID when available. |

Resolve `health-check` to the task UUID before storing an association. Task renames must not break discovery or rewrite run history. Preserve historical task, run, and branch associations when a task completes, an Orb reconnects, or a nickname changes.

Record both the source event time, when available, and the host's observation time. Late events from an older connection must not reopen an association or mark the current connection online. Keep historical events for inspection without treating those events as current presence.

## Report connection state honestly

`online` requires a confirmed connection and sufficiently recent heartbeat evidence. An Orb appearing in stored history is not evidence that the Orb remains connected.

| State | Meaning |
| --- | --- |
| `online` | The current connection passed the connector's identity and liveness checks within the agreed freshness window. |
| `offline` | Molly has a confirmed disconnect or an explicit connection-closed result. |
| `unknown` | Molly lacks fresh evidence, a check failed without proving disconnection, or the connection's state cannot be established. |

Choose the heartbeat interval and freshness window from the verified protocol and observed behavior. Do not invent timing defaults now. Report the last confirmation and check time so the user can judge stale evidence.

Connection state and ability to accept work are separate. Show `busy`, `available`, or `unknown` only when the connector can establish that information. An online Orb may already be occupied. Reconnecting does not automatically restore a previous task as the Orb's current assignment; require fresh assignment evidence.

## Find the Orb associated with a task

The proposed lookup accepts either a task nickname or UUID:

```bash
php artisan molly:orbs --task health-check
```

Resolve the task once, then apply these rules in the shared action:

1. Find Orbs with confirmed current `online` connections. Keep offline and unknown history available for inspection, but do not include those records as connected candidates.
2. Rank confirmed current associations with that exact task UUID first.
3. Rank prior exact associations next, newest association first. Use each Orb's most recent confirmed association with that task, including related run and branch records. Do not let an unrelated newer task erase that history.
4. Preserve ties. Show every equally ranked Orb and the shared matching time. Stable ID ordering may make the display predictable, but must not turn an ordering choice into a recommendation.

Return the matching reason, task UUID and current nickname, Orb ID, association time, association source, run or branch reference, connection state, and last heartbeat time. Show the timestamp used for ordering. Define the protocol's event-time handling before using remote timestamps to decide recency.

A current association means confirmed work on the requested task, not merely the Orb's latest stored task name. A prior exact association remains a prior match even if the Orb is now busy with another task. Show that current work separately before offering any execution action.

If no connected Orb has an exact association, say so. Listing Orbs and looking up associations must not execute the task, connect to a new target implicitly, or choose a target silently. Starting work is a separate explicit action that rechecks connection freshness, capabilities, workspace access, and the user's chosen target.

## CLI, MCP, and web flows

All entries below are proposed. `CONNECTION` remains a placeholder until the real connector defines how to identify and authenticate a connection. Credentials must not appear in task reports or discovery output.

| Operation | Proposed CLI | Proposed MCP tool | Proposed local web flow |
| --- | --- | --- | --- |
| List connections and match a task | `molly:orbs --task health-check` | `molly_orbs_list`, with an optional task reference | Open Orbs or a task's connected-Orbs section. Submit a task nickname or UUID to filter. |
| Connect | `molly:orb:connect CONNECTION` | `molly_orb_connect` | Submit a native connection form and read the confirmed result. |
| Refresh presence | `molly:orb:refresh ORB_ID` | `molly_orb_refresh` | Submit **Check connection** and read the recorded state and time. |
| Disconnect | `molly:orb:disconnect ORB_ID` | `molly_orb_disconnect` | Submit **Disconnect** and retain the connection history. |

Every CLI entry uses Artisan. CLI tables use Laravel Prompts, with `--json` for scripts. MCP tools return the same identifiers, states, reasons, and timestamps as the CLI result. Mark lookup as read-only in the tool contract. Connecting, refreshing, and disconnecting describe their actual side effects separately.

The web interface renders complete HTML and uses native forms with CSRF protection. JavaScript may refresh visible state, but connection management and task lookup must work without JavaScript. Show words for state and matching reason; do not rely on color alone. Preserve the existing local access guard unless a separate access-control change is implemented and verified.

Connection management over MCP means Molly exposes the same management actions to an MCP client. It does not establish that the Orb's own connection protocol is MCP. If the selected Orb connector uses MCP, document that separate client connection after confirming the protocol.

## Related work is an optional suggestion

Exact task associations need no model. Add related-work suggestions only if exact lookup leaves a demonstrated user need. Require an explicit request and label the result as a semantic suggestion, with its evidence and model metadata. A related title, similar prompt, or classification probability is not proof that an Orb worked on the same task.

Keep suggestions separate from exact matches. Suggestions must not change task association history, override a current exact match, select an execution target, or establish that an Orb is connected.

As checked on September 17, 2026, [Laravel AI's TypeSafe classification proposal](https://github.com/laravel/ai/pull/1010) is an open draft. Molly's installed `laravel/ai` version is `0.11.2`, which has no `Classification` API. Evaluate the released API and provider requirements if the proposal ships. Do not install a pull-request branch or claim TypeSafe classification exists in the current Molly build.

## Questions the first connector must answer

The actual Orb protocol has not been established for this plan. Before implementation, verify:

- How an Orb identifies itself and authenticates a connection.
- How Molly discovers an endpoint and distinguishes a reconnect from a different Orb.
- Whether the protocol sends heartbeats, disconnects, task assignments, releases, run IDs, and branch IDs.
- Which source timestamps are trustworthy and how reconnects handle delayed events.
- Whether the connector can report capabilities, workspace access, and busy state.
- What a disconnect does to active work and whether cancellation has a confirmed result.

If the protocol cannot establish a fact, return `unknown` or omit the unsupported association. Do not infer a successful disconnect, running task, or completed branch from a missing heartbeat. No environment variables or credential-storage settings are proposed until the connector is selected.

## Implementation and publishing milestones

1. Confirm one real connector's protocol and record the supported behavior. Capture a local test fixture for handshake, heartbeat, disconnect, and association messages that the connector actually provides.
2. Implement connection records and exact task lookup through the shared actions. Keep identity and task UUIDs stable across reconnects and renames. Demonstrate a real connection and lookup without starting work.
3. Expose CLI, MCP, and native web flows over those actions. Compare results from all three interfaces for the same stored evidence. Keep side-effecting connection controls separate from lookup.
4. Verify failures, then publish the working command and setup references. Move implemented behavior from this plan into the user guides. Keep unsupported protocol features and semantic suggestions marked as planned.

Tests should cover reconnect identity, duplicate and late events, heartbeat expiry, explicit disconnect, unknown presence, nickname changes, and preserved run and branch history. Verify current exact matches before prior exact matches, recency ordering, ties, and the exclusion of stale connections from connected candidates. Prove that lookup calls neither execution nor target-selection actions.

CLI, MCP, and web parity tests should compare normalized results and failure behavior. Web checks must cover native form submission, keyboard access, visible status text, and operation without JavaScript. Run one real connector acceptance check alongside fixture tests before publishing a support claim.

Publish the plan and later implementation guides through the existing [documentation publishing process](publishing.md). GitHub Pages hosts documentation only. Orb connections, credentials, MCP endpoints, and task execution remain in the host application. The proposed `mary.win` documentation subdomain does not become an Orb endpoint.

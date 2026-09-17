---
layout: default
title: Find task connections
---

# Find task connections

Link an Amp thread to a saved Molly task, then check whether Amp reports a connected executor. Molly keeps earlier task associations when you link the same thread to another task.

A saved link records your assertion about the thread. Amp's connection state is separate evidence. Neither fact proves that an Orb is working on the Molly task, and neither operation starts work.

## Link an Amp thread

Create a [saved task](tasks.md#create-a-task) first. Copy the thread ID from an Amp thread URL. Use the `T-` prefix and UUID, without the rest of the URL.

Replace the example thread ID below with the ID you copied:

```bash
php artisan molly:link-thread health-check T-00000000-0000-0000-0000-000000000000
```

`health-check` can be a task nickname or UUID. Molly trims surrounding spaces and normalizes the thread ID to `T-` followed by lowercase hexadecimal characters. Invalid IDs return `AMP_THREAD_INVALID`; a missing task returns `TASK_NOT_FOUND`.

Linking does not need an Amp installation or login. Molly validates the ID's format and saves the association without checking whether the thread exists. Repeating the latest link to the same task returns the existing association and keeps its original time. Linking the thread to another task records a new association. Linking back later records another association without deleting the intervening history.

The database stores the task UUID. Renaming a task preserves the links.

## Read task associations

Read the saved history without contacting Amp:

```bash
php artisan molly:connections health-check --stored
```

Molly shows one match per linked thread, using that thread's most recent association with the requested task.

| Association | Meaning |
| --- | --- |
| `latest_recorded` | The thread's latest user-recorded link names this task. This does not establish current work. |
| `prior_exact` | The thread was linked to this exact task UUID, but the latest recorded link names another task. The report includes that other task. |

The lookup selects the 20 most recently recorded thread associations for the task. Within those results, connected executors appear first, then latest recorded task links, then newer association records. Unknown and disconnected history remains visible. This ordering does not choose an execution target.

If more than 20 threads match, the terminal and web reports say the result is limited. JSON returns `truncated: true`. There is no pagination option in this release.

## Check current connection evidence

For a live check, the host process must be able to run an authenticated `amp` executable. Molly uses the Amp CLI's existing login. A web server running under another account or a different `PATH` may not have the same access as your terminal.

```bash
php artisan molly:connections health-check
php artisan molly:connections health-check --json
```

The terminal report uses Laravel Prompts. JSON includes `observed_at`, the host's check time, and `provider_version` when Amp returns a recognizable version. Saved association time appears separately as `linked_at`.

| Connection | Meaning |
| --- | --- |
| `connected` | A fresh, non-reconnecting Amp snapshot explicitly reports a connected executor for the thread. |
| `disconnected` | A fresh, non-reconnecting snapshot explicitly reports no connected executor for the thread. |
| `unknown` | Molly has no reliable current observation. Stored-only reads, missing threads, reconnects, stale snapshots, and failed checks can produce this state. |

`working` is a separate boolean or `null` in JSON. The web report shows **Working** or **Not working** only when Amp provides an observed value. Working does not prove that the executor is working on this Molly task. An unknown value does not mean idle.

The raw `executor_type` may be available in JSON. Molly does not classify that value as an Orb. Every lookup returns `orb_identity: "unverified"`.

The report's overall `status` is `observed` when at least one requested thread has connection evidence, `unknown` when no requested thread has a usable observation, `unavailable` when the check fails, or `not_checked` for stored-only reads and tasks with no links. Read each match and the report's `reason`; `observed` does not mean every requested thread is connected.

`molly:connections` exits `0` when Molly returns the report, including an empty report or an unavailable Amp observation. A missing task or another handled command error exits `1`. Scripts must inspect the JSON fields rather than treating exit code `0` as proof of a connection. See the [command reference](reference/commands.md#json-results-and-exit-codes).

## Use the web interface

Enable the [local web interface](web-interface.md), open a task, and choose **Find linked Amp threads**. Enter the thread ID and submit **Link thread**. The form saves the association and returns to the task's connection history.

Opening the page reads stored history only. Choose **Check connections** to contact Amp. The resulting page shows the check time, provider version when known, connection states, and observed working states. The forms render complete HTML and work without JavaScript.

Connection observations are not saved in the database. Reopening the page shows stored associations with unknown connection state until you check again. A failed check preserves the association history.

The connection page uses Molly's existing local access guard and CSRF protection. There is no new remote endpoint or background refresh worker.

## Read connections through MCP

Connect a client to [Molly's local MCP server](agents.md#mcp-tools). Call `molly_connections` with `task` set to a nickname or task UUID. Set `stored` to `true` for a stored-only read; omit `stored` or use `false` to check Amp. The tool is read-only and returns the same report as `molly:connections`.

To save an association, call `molly_task` with `operation: "link_thread"`, the task reference in `id`, and the Amp thread ID in `thread`. That operation changes association history without starting an executor. The recorded source remains `user`; an agent recording the link does not turn the assertion into provider-confirmed work.

## Read limits and failures

`src/Actions/ReadAmpConnections.php` runs only `amp --version`, `amp top --stream-jsonl`, and exports for the requested thread IDs. The report excludes thread titles and conversation content. The observer uses argument arrays and never sends a prompt, continues a thread, or starts an executor.

The observer allows 10 seconds across the read. Version and export commands each have a two-second limit; the connection stream has a three-second observation window. Output limits are 4 KiB for the version, 1 MiB for the stream, 5 MiB per export, and 8 MiB across the read. These limits are fixed in the action, not environment settings.

Amp marks the stream format experimental. Molly validates complete snapshots and requires their timestamps to be within 30 seconds of the host clock. An initial empty snapshot does not establish that every executor is disconnected. A later reconnect or missing thread removes earlier connection evidence from that read.

If a check fails, keep the saved links and read the reason. Confirm that the host process can find Amp, that Amp is authenticated, and that the host clock is accurate. Malformed, stale, or oversized output remains unavailable. A missing executor type leaves the type unknown while preserving valid connection evidence.

The implementation uses `src/Actions/LinkTaskThread.php`, `src/Actions/FindTaskConnections.php`, and `src/Actions/ReadAmpConnections.php`. The commands live in `src/Console/MollyLinkThreadCommand.php` and `src/Console/MollyConnectionsCommand.php`. `src/Http/TaskConnectionController.php` passes the same action results to `resources/views/connections.blade.php`.

Verified Orb identity, Orb connect and disconnect controls over CLI, web, and MCP, and remote execution selection remain in the [execution target plan](execution-targets.md).

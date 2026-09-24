---
layout: default
title: Amp thread links
---

# Amp thread links

When you work on a task in an Amp conversation, you can save the link between the two so that later you can find which thread discussed which task. Molly can also ask Amp whether that thread currently has a connected executor. Neither starts work, and neither proves where a run executed.

## Link a thread

Copy the `T-…` thread ID from Amp:

```bash
php artisan molly:link-thread ready-check T-00000000-0000-0000-0000-000000000000
```

Molly checks the ID's shape and saves your assertion. It does not contact Amp to confirm the thread exists.

## Read the saved links

```bash
php artisan molly:connections ready-check --stored
```

This never contacts Amp. Each saved link is `latest_recorded` when the thread's most recent link names this task, or `prior_exact` when the thread was linked to this task before but was later linked to another.

## Ask Amp about the thread

```bash
php artisan molly:connections ready-check
```

The host process needs a logged-in `amp` executable. The result reports `connected`, `disconnected`, or `unknown` for each linked thread, plus the raw executor type Amp reported and whether Amp says the executor is working. None of that proves the executor is working on this task, and an executor type is not a verified Orb identity. Observations are not saved as task history.

## Web and MCP

The task page has a "Find linked Amp threads" link. Through MCP, `molly_connections` reads links and observations, and `molly_task` with `operation: link_thread` saves one.

## Next

- [Agents and MCP](agents.md)
- [Execution targets](execution-targets.md), the planned remote work

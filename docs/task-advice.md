---
layout: default
title: Task advice
---

# Task advice

Use task advice when you want Molly to explain what action is allowed next for a saved task.

```bash
php artisan molly:advice health-check
```

Advice never starts, retries, or stops a task.

## What Molly checks first

Molly uses saved state and attempt limits before any optional model evaluation.

| Task state | Typical advice |
| --- | --- |
| `pending` | Start it if an attempt is available. |
| `running` | Inspect the active work and wait or stop. |
| `completed` | Review the evidence and changed files. |
| `failed` | Inspect failure evidence, then retry only if allowed. |
| `stopped` | Inspect retained changes before deciding whether to retry. |

If the attempt limit is reached, advice cannot authorize another retry.

## Optional TypeSafe guidance

TypeSafe is optional. Molly only asks it for eligible failed tasks when:

- TypeSafe is enabled and configured
- the latest attempt has usable failure evidence
- another attempt is still allowed

Molly sends a bounded summary, not the complete report.

If TypeSafe is disabled, unavailable, low-confidence, or returns invalid output, Molly keeps deterministic local advice and explains the fallback.

A TypeSafe response cannot change saved test results or bypass the attempt limit.

## Web and MCP

In the local UI, open a task and choose **Get next-step advice**.

Through MCP:

```json
{"operation":"advice","id":"health-check"}
```

## JSON output

```bash
php artisan molly:advice health-check --json --no-interaction
```

The result includes the observed task state, retry permission, recommended action, optional command, evidence references, and provider metadata when TypeSafe was used.

## Next

- [Manage tasks](tasks.md)
- [Troubleshooting](troubleshooting.md)
- [Configuration](reference/configuration.md)

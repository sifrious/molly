---
layout: default
title: Task advice
---

# Task advice

Advice tells you which action a saved task allows next and why. It reads the saved state; it never starts, retries, or stops anything.

```bash
php artisan molly:advice ready-check
```

## What it says

| Task state | Advice |
| --- | --- |
| `pending` | Start it. |
| `running` | Wait, or inspect and stop. |
| `completed` | Done. Review the diff and the evidence. |
| `failed` | Inspect the evidence, then retry if attempts remain. |
| `stopped` | Inspect the working tree, then retry if attempts remain. |

Advice includes the command to run next, the attempts used against the limit, and whether a retry is allowed. When the limit is reached, the advice is to stop, and no model is consulted.

Molly saves the advice with the run it describes. If the task changed while advice was being prepared, Molly discards the recommendation and tells you the evidence moved.

## Advice from Jev

When Jev is enabled and a failed task still has attempts left, Molly also asks Jev whether the evidence supports another attempt. It sends a bounded summary: the request, the Pest counts, the Tarpit check statuses, and up to three findings for the task's files. It never sends secrets, full test output, or the whole report.

Jev answers with one of `continue`, `retry`, `stop`, or `needs_review`, plus a confidence. Molly applies it like this:

| Jev says | Advice becomes |
| --- | --- |
| `retry` with enough confidence | Retry. The recorded failure stands until a new attempt passes. |
| `stop` with enough confidence | Stop, even though the attempt limit would allow more. |
| `continue` or `needs_review` | Inspect. A model saying "continue" never marks a failed task as passing. |
| Confidence below the threshold | Molly keeps its own guidance and records `low_confidence`. |

The result lists the provider, the model that answered, the confidence, the threshold, and the probability of each option, so you can see what Jev thought without trusting it blindly.

When Jev is off, advice records `jev_disabled`. When it is on but the installed `laravel/ai` cannot classify, advice records `capability_missing` and falls back; when the credential is missing, `invalid_config`. A provider failure records `provider_error` and keeps no payload. `molly:doctor` reports the same states. See [Jev](reference/configuration.md#jev).

## Web and MCP

On a task page in the [web interface](web-interface.md), choose "Get next-step advice". The page shows the same advice and the same provider block.

Through MCP:

```json
{"operation": "advice", "id": "ready-check"}
```

## JSON

```bash
php artisan molly:advice ready-check --json --no-interaction
```

The JSON carries the observed state, `retry_allowed`, `next_action`, `command`, evidence references, the provider block, and whether the advice was persisted with the run.

## Next

- [Tasks](tasks.md)
- [Verification](verification.md)
- [Configuration](reference/configuration.md#jev)

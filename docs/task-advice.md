---
layout: default
title: Task advice
---

# Task advice

Ask Molly what to do next with a saved task:

```bash
php artisan molly:advice health-check
```

Use a nickname or UUID. Molly explains the recommended action, whether retry is allowed, and how many attempts the task has used. The command returns advice. It does not start, retry, or stop the task.

## Request advice on the web or through MCP

Open a task in the [web interface](web-interface.md), then choose `Get next-step advice`. The native form posts to `/molly/tasks/TASK_UUID/advice` with the default route prefix. It works without JavaScript. Reading a task or run page does not request TypeSafe advice. A provider request requires an explicit form submission, CLI command, or MCP operation.

An MCP client can call `molly_task` with the same task nickname or UUID:

```json
{"operation":"advice","id":"health-check"}
```

The tool returns the result under `advice`. CLI, MCP, and web requests call the same application action and enforce the same state and attempt limits.

## Read the decision

Molly checks the task state and attempt limit first:

| Saved state | Advice |
| --- | --- |
| Pending | Start the task if an attempt remains. |
| Running | Wait and inspect the current evidence. |
| Completed | Review the saved evidence and changed files. |
| Failed or stopped at the attempt limit | Stop this approach. Another attempt is blocked. |
| Stopped with attempts remaining | Inspect applied changes before choosing whether to retry. |
| Failed with attempts remaining | Inspect the failed evidence. TypeSafe may recommend another step when configured. |

An invalid `molly.max_attempts` value produces inspection advice and blocks any recommendation to execute another attempt. Fix the setting before starting or retrying.

Retry permission and a recommendation are separate. TypeSafe may recommend stopping even though the attempt limit still permits a retry. Failed checks remain failed. A TypeSafe `continue` answer becomes `inspect`, and a `needs_review` answer also becomes `inspect`.

## Optional TypeSafe guidance

The existing `molly.typesafe` settings control TypeSafe. Advice consults the provider only for a failed task whose latest attempt failed, has verification or Tarpit evidence, and whose attempt limit permits another run. Missing evidence produces local inspection advice. One explicit advice request makes at most one provider request.

The request contains a bounded task prompt, recorded Pest status and counts, Tarpit check statuses, and up to three findings for allowed files. Blocking findings come first. Molly sends no full test output, complete report, source snapshot, environment file, or provider configuration in the evidence.

When TypeSafe is disabled, has no usable configuration, times out, returns an invalid response, or falls below its confidence threshold, Molly keeps deterministic advice and explains the fallback. It does not invent a model answer. If task evidence changes during the request, Molly discards the recommendation and reports the current saved state.

## Inspect saved advice

```bash
php artisan molly:advice health-check --json --no-interaction
```

JSON includes task and run UUIDs, the current task reference, recommended action, retry permission, and a permitted command when applicable. It records the observed state, attempt counts, and evidence references. Provider metadata includes the choice, probabilities, configured threshold, and evaluation duration when available. The result also records confidence, fallback status, persistence status, and the time of the request.

Molly stores the latest advice in `report.advice` when the latest run is completed, failed, or stopped and its evidence remains unchanged. Requesting advice again replaces that field while preserving the other evidence. Running runs return guidance without writing into a report that execution is still updating. A task with no run returns advice without creating a run. The JSON `persisted` field tells you whether Molly stored the advice. Advice is a snapshot of the observed state. Execution commands check current state and limits again.

The application behavior is in `src/Actions/RecommendTaskNextStep.php`. The CLI in `src/Console/MollyAdviceCommand.php` uses Laravel Prompts to report the result. See [tasks](tasks.md) for retry behavior and [configuration](reference/configuration.md) for TypeSafe settings.

# Planning

A plan is for a request that is too large for one task. It saves your answers to five questions so the tasks you create afterward start from decisions instead of guesses. A plan does not write code, start a task, or prove anything.

## Start a plan

```bash
php artisan molly:plan 'Let people inspect and retry failed tasks from one page.'
```

Molly asks the questions one at a time and saves each answer before the next:

| Step | The question |
| --- | --- |
| `outcome` | What must the user be able to do, and what is out of scope? |
| `state` | What must be stored, and what can be computed? |
| `laravel` | What does Laravel or the application already provide? |
| `boundaries` | Is a new interface, package, or layer needed? |
| `verification` | Which tests and review evidence will prove it? |

Each question comes with short bundled passages from the Laravel documentation and Mary Perry's Tarpit notes. They are there to prompt you, not to decide for you.

Come back later:

```bash
php artisan molly:plan --resume=PLAN_ID
```

## Skip the questions

If you already made those decisions:

```bash
php artisan molly:plan 'Add a read-only task summary.' --skip-review
```

Skipping records that you chose not to answer. It is not a review.

## From a script

```bash
php artisan molly:plan 'Show pending tasks.' --json --no-interaction
php artisan molly:plan --resume=PLAN_ID --step=outcome \
  --answer='Show pending tasks on one screen. Editing them is out of scope.' \
  --json --no-interaction
```

The JSON includes `next_step`, so a script knows which question is next.

## Turn a plan into tasks

Open the plan in the [web interface](web-interface.md) or use the `molly_task` MCP tool with `operation: from_plan`. Each task still needs one small change, a workspace, allowed files, and a required Pest test. The plan gives the task context; it does not loosen those limits. There is no Artisan command that converts and starts a whole plan at once.

## Ask Jev which question to revisit

With [Jev](reference/configuration.md#jev) enabled, a plan can ask which planning area most needs another look. Request it from the plan page or with the `molly_plan` tool's `suggest` operation. The answer names one step, with a confidence, and links the sources for that step. It does not answer the question for you, and it goes stale when you change an answer; the page tells you when that happens.

## Next

- [Tasks](tasks.md)
- [Web interface](web-interface.md)
- [Laravel knowledge](knowledge-graph.md)

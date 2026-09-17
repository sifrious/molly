---
layout: default
title: Planning
---

# Planning

Use planning when a request is too large to become one Molly task immediately.

A plan stores your decisions. It does not edit code, start a task, or prove that any implementation is correct.

## Start a plan

```bash
php artisan molly:plan 'Let people inspect and retry failed tasks from one page.'
```

By default, Molly asks five questions:

| Step | What you decide |
| --- | --- |
| `outcome` | What the user must be able to do and what is out of scope |
| `state` | What must be stored and what can be derived |
| `laravel` | What existing Laravel or application behavior already helps |
| `boundaries` | Whether a new interface, package, or layer is actually needed |
| `verification` | What tests and review evidence will prove the requirement |

Molly saves each answer before asking the next one.

Resume later:

```bash
php artisan molly:plan --resume=PLAN_ID
```

## Skip the guided questions

If you already made those decisions:

```bash
php artisan molly:plan 'Add a read-only task summary.' --skip-review
```

Skipping means you chose not to use the guided questions. It does not count as a passing implementation review.

## Use planning from scripts

Create a draft:

```bash
php artisan molly:plan 'Show pending tasks.' --json --no-interaction
```

Save one answer:

```bash
php artisan molly:plan --resume=PLAN_ID \
  --step=outcome \
  --answer='Show pending tasks on one screen. Editing tasks is out of scope.' \
  --json --no-interaction
```

The JSON `next_step` field tells you which step comes next.

## Turn a plan into tasks

A ready plan can create pending tasks through the local web interface or Molly's MCP task tool.

Each task still needs:

- one small change
- a workspace
- allowed files
- a required Pest test

The plan provides context. It does not replace those task boundaries.

There is currently no Artisan option that automatically converts and starts every plan item.

## Bundled guidance

Molly includes a small offline planning guide with selected Laravel, Tarpit, and NativePHP material. It is a curated bundle, not a full copy of those manuals.

A topic match tells Molly which material may be relevant. It does not mean Molly should automatically add a queue, interface, package, or native integration.

## Optional TypeSafe suggestion

If TypeSafe is enabled, a plan can explicitly ask for a suggestion about which planning area deserves another look.

That suggestion:

- does not answer a planning question for you
- does not start a task
- does not replace Pest or Tarpit
- can become stale when plan answers change

## Next

- [Manage tasks](tasks.md)
- [Agents and MCP](agents.md)
- [Configuration](reference/configuration.md)

---
layout: default
title: Project graph
---

# Project graph

Molly can rebuild a small local graph of saved tasks, acceptance tests, files, attempts, and verification blockers.

This graph is separate from the Laravel documentation graph. It is local, disposable, and stored in `.molly/knowledge.sqlite` by default.

## Build it

```bash
php artisan molly:project:index
php artisan molly:project:index --workspace=/path/to/workspace
```

Indexing reads saved Molly records for that workspace. It does not start an agent.

## Query it

```bash
php artisan molly:project:query TASK_UUID
php artisan molly:project:query pest --relation=blocked_by
php artisan molly:project:query tests/GreetingTest.php --depth=1 --limit=20
```

Use `--json` for scripts. Query limits match the Laravel knowledge graph:

- depth: 0 through 3
- maximum nodes: 40
- optional relationship filters

## What it shows

Nodes include:

- tasks
- acceptance tests
- files
- runs
- verifier evidence
- blockers
- the workspace
- imported GitHub issues when present

Relationships include `implements`, `verified_by`, `changes`, `runs_in`, `blocked_by`, and `produced`.

Tarpit findings and required verifier failures stay evidence. They are not rewritten as vague task labels such as "needs work".

## What it does not do

The graph is read-only. It does not merge, open a pull request, or change verifier policy.

A visual Bloom view is still planned. The Artisan commands are the current inspection path.

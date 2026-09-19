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

Relationships include `implements`, `verified_by`, `changes`, `runs_in`, `blocked_by`, `approved_by`, and `produced`.

Locked Pest tests appear as approval nodes. Tarpit findings and required verifier failures stay evidence. They are not rewritten as vague task labels such as "needs work".

## Inspect it in the browser

When the local web interface is enabled, open `/molly/graph` and choose the workspace. The page rebuilds the same graph, lists verification blockers first, and stops at 40 nodes.

This is a local Molly page. It is not Bloom's inspector.

## What it does not do

The graph is read-only. It does not merge, open a pull request, or change verifier policy.

A visual Bloom view is still planned. Artisan commands and the local Molly page are the current inspection paths.

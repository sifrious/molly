---
layout: default
title: Laravel knowledge
---

# Laravel knowledge

Molly can build a small local knowledge graph for Laravel so agents can retrieve connected documentation and framework source without loading a whole manual into the prompt.

The current Laravel namespace covers queues only. Saved Molly tasks live in a separate project graph. See [Project graph](project-graph.md).

## Build the graph

```bash
php artisan molly:knowledge:index laravel
```

Molly detects the installed Laravel major version and rebuilds the snapshot for that version.

For a script that should fail when the installed version is not what you expect:

```bash
php artisan molly:knowledge:index laravel --laravel-version=13
```

Running the index more than once does not create duplicate logical nodes or relationships.

## Query it

```bash
php artisan molly:knowledge:query Queue
php artisan molly:knowledge:query Retry --depth=1 --limit=10
php artisan molly:knowledge:query Job --relation=uses
```

A query first looks for an exact concept or symbol. If there is no exact match, it falls back to a partial name match.

The result is intentionally small:

- depth: 0 through 3
- maximum nodes: 40
- stable result ordering
- optional relationship filters

Use `--json` for scripts.

## What is in the queue graph

The first index connects concepts such as:

- jobs
- `ShouldQueue`
- retries
- middleware
- queue testing
- relevant documentation sections
- relevant framework symbols

Relationships include examples such as `documented_in`, `configured_by`, `implements`, `uses`, and `tested_by`.

## Provenance

Every returned node and relationship has source information. A source can identify:

- the Laravel version
- documentation or framework source
- documentation URL or local source path
- pinned revision and digest
- retrieval date or PHP symbol when available

Molly does not invent a relationship at query time. The query can only return relationships created by the indexer.

## Local storage

By default, Molly stores the graph here:

```text
.molly/knowledge.sqlite
```

The file is disposable. Delete it and rebuild whenever you want.

To use another local path:

```dotenv
MOLLY_KNOWLEDGE_DATABASE=.molly/another-name.sqlite
```

Molly uses PDO SQLite directly. No hosted graph database is required.

## Current scope

Current:

- Laravel queues
- bundled queue documentation guidance
- matching installed framework source
- local version-aware SQLite storage
- CLI and read-only MCP queries

Planned later:

- more Laravel documentation areas
- separate namespaces for Pest, PHP, NativePHP, Super Native, and selected packages
- a separate project and blocker graph

The project graph is separate work. It should reuse the small graph records without mixing project tasks into the Laravel queue index.

## Next

- [Agents and MCP](agents.md)
- [Command reference](reference/commands.md)
- [Configuration](reference/configuration.md)

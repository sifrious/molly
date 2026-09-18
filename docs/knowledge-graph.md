---
layout: default
title: Laravel knowledge
---

# Laravel knowledge

Molly can build a small local knowledge graph for Laravel so agents can retrieve connected documentation and framework source without loading a whole manual into the prompt.

The current Laravel namespace covers queues, routing, testing, and validation. Saved Molly tasks live in a separate project graph. See [Project graph](project-graph.md).

## Build the graph

```bash
php artisan molly:knowledge:index laravel
```

Molly detects the installed Laravel major version and rebuilds the snapshot for that version.

For a script that should fail when the installed version is not what you expect:

```bash
php artisan molly:knowledge:index laravel --laravel-version=13
php artisan molly:knowledge:index laravel --laravel-version=12
```

Running the index more than once does not create duplicate logical nodes or relationships.

## Query it

```bash
php artisan molly:knowledge:query Queue
php artisan molly:knowledge:query Route
php artisan molly:knowledge:query Pest
php artisan molly:knowledge:query Validation
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

## What is in the graph

The queue slice connects concepts such as:

- jobs
- `ShouldQueue`
- retries
- middleware
- queue testing
- relevant documentation sections
- relevant framework symbols

The routing slice connects `Route`, `routes/web.php`, the Basic Routing section, and the installed `Illuminate\Support\Facades\Route` symbol.

The testing slice connects Pest, PHPUnit, `tests/Feature`, the Introduction section, and `Illuminate\Foundation\Testing\TestCase`.

The validation slice connects `Validation`, the Introduction section, and the installed `Illuminate\Validation\Validator` symbol. It does not claim `Illuminate\Http\Request::validate`, which this package Request class does not implement.

Relationships include examples such as `documented_in`, `configured_by`, `implements`, `uses`, and `tested_by`.

The requested Laravel major version must match the installed major version. Bundled documentation excerpts currently come from Laravel 13. On Laravel 12 they are still indexed against the installed major, with the excerpt provenance unchanged.

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

- Laravel queues, routing, testing, and validation
- bundled documentation excerpts for those areas
- matching installed framework source
- local version-aware SQLite storage
- CLI and read-only MCP queries

Planned later:

- more Laravel documentation areas
- separate namespaces for Pest, PHP, NativePHP, Super Native, and selected packages

The project graph is separate work. It reuses the small graph records without mixing project tasks into the Laravel knowledge index.

## Next

- [Agents and MCP](agents.md)
- [Command reference](reference/commands.md)
- [Configuration](reference/configuration.md)

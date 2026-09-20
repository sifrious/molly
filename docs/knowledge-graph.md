---
layout: default
title: Laravel knowledge
---

# Laravel knowledge

Molly can build a small local knowledge graph for Laravel so agents can retrieve connected documentation and framework source without loading a whole manual into the prompt.

The current Laravel namespace covers queues, routing, testing, validation, the container, Eloquent, and events. NativePHP Desktop v2 and Mobile v4 live in a separate `nativephp` namespace. Bundled tarpit notes live in a separate `tarpit` namespace. Saved Molly tasks live in a separate project graph. See [Project graph](project-graph.md).

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
php artisan molly:knowledge:query Container
php artisan molly:knowledge:query Eloquent
php artisan molly:knowledge:query Events
php artisan molly:knowledge:query Retry --depth=1 --limit=10
php artisan molly:knowledge:query Job --relation=uses
php artisan molly:knowledge:index nativephp
php artisan molly:knowledge:query Desktop --namespace=nativephp --nativephp-version=desktop-2
php artisan molly:knowledge:query Mobile --namespace=nativephp --nativephp-version=mobile-4
php artisan molly:knowledge:index tarpit
php artisan molly:knowledge:query Tarpit --namespace=tarpit
php artisan molly:knowledge:query Cleverness --namespace=tarpit
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

The container slice connects `Container`, the Zero Configuration Resolution section, and the installed `Illuminate\Container\Container` symbol.

The Eloquent slice connects `Eloquent`, the Introduction section, and the installed `Illuminate\Database\Eloquent\Model` symbol.

The events slice connects `Events`, the Introduction section, and the installed `Illuminate\Events\Dispatcher` symbol.

The NativePHP namespace indexes bundled Desktop v2 and Mobile v4 introductions as separate versions (`desktop-2` and `mobile-4`). A Desktop query cannot return Mobile nodes. Molly does not require or claim an installed NativePHP package.

The tarpit namespace indexes Mary Perry's bundled talk notes as `notes-YYYY-MM-DD`. Those notes discuss essence, accident, and cleverness. They are not a quality score and cannot override Pest, Tarpit checks, or Clever measurements.

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

Implementation runs receive a bounded `laravel_knowledge` neighborhood chosen from the task, allowed files, and required test. When the task names NativePHP desktop or mobile, they also receive `nativephp_knowledge`. When it names tarpit, cleverness, or essential versus accidental complexity, they also receive `tarpit_knowledge`. That context is advisory. Missing or empty neighborhoods do not fail the run, widen file scope, or override Pest.

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

- Laravel queues, routing, testing, validation, the container, Eloquent, and events
- NativePHP Desktop v2 and Mobile v4 as a separate namespace
- bundled tarpit notes as a separate namespace
- bundled documentation excerpts for those areas
- matching installed Laravel framework source
- local version-aware SQLite storage
- CLI and read-only MCP queries

Planned later:

- more Laravel documentation areas
- separate namespaces for Pest, PHP, Super Native, and selected packages

The project graph is separate work. It reuses the small graph records without mixing project tasks into the Laravel knowledge index.

## Next

- [Agents and MCP](agents.md)
- [Command reference](reference/commands.md)
- [Configuration](reference/configuration.md)


## JIT context pack

Agent runs receive a versioned `ContextPack` (`molly.context_pack.v1`) built from task needles — not a raw graph dump and not a default Queue neighborhood.

```bash
php artisan molly:knowledge:pack "Validate the queued greeting route." --file=routes/web.php --test=tests/GreetingTest.php --json
```

The pack records query inputs, selected concepts, deterministic selection reasons, provenance, and empty/unavailable statuses when nothing useful matches. It remains advisory: it cannot widen allowed files, change a protected test, or declare completion.


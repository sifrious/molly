---
layout: default
title: Laravel knowledge
---

# Laravel knowledge

Molly ships short excerpts of the Laravel documentation and can index them together with the framework source installed in your application. An agent then gets a few connected, version-matched pages in its prompt instead of a whole manual, and every page carries its source.

The same store also holds NativePHP knowledge and Mary Perry's Tarpit notes, each in its own namespace, and the [project graph](project-graph.md) of your own tasks.

## Build the graph

```bash
php artisan molly:knowledge:index laravel
```

Molly detects the installed Laravel major version and indexes for it. Running the command again refreshes the snapshot without duplicating anything. To fail fast when the version is not what a script expects:

```bash
php artisan molly:knowledge:index laravel --laravel-version=13
```

## Query it

```bash
php artisan molly:knowledge:query Queue
php artisan molly:knowledge:query Retry --depth=1 --limit=10
php artisan molly:knowledge:query Job --relation=uses
```

A query looks for an exact concept or symbol first, then a partial name match. Results are small on purpose: depth 0 to 3, at most 40 nodes, in a stable order. Add `--json` for scripts.

## What is covered

The Laravel namespace covers queues, routing, testing, validation, the container, Eloquent, and events. Each slice connects a documentation section, the concepts it introduces, and the installed framework symbols, with relationships such as `documented_in`, `configured_by`, `implements`, `uses`, and `tested_by`. The bundled excerpts come from the Laravel 13 documentation; on a Laravel 12 application they are indexed against the installed major with that provenance recorded.

NativePHP Desktop 2 and Mobile 4 are separate versions in the `nativephp` namespace, and a Desktop query never returns Mobile nodes:

```bash
php artisan molly:knowledge:index nativephp
php artisan molly:knowledge:query Desktop --namespace=nativephp --nativephp-version=desktop-2
```

The Tarpit notes index Mary Perry's talk notes on essential and accidental complexity:

```bash
php artisan molly:knowledge:index tarpit
php artisan molly:knowledge:query Cleverness --namespace=tarpit
```

The notes are reading material for the agent and for you. They are not a score and cannot change a verification result.

## Provenance

Every node and relationship records where it came from: the Laravel version, whether it is documentation or source, the URL or file path, a pinned revision and digest, and the PHP symbol when there is one. A query returns only relationships the indexer created; nothing is invented at query time.

## What the agent sees

For each implementation run, Molly selects a small neighborhood from the task text, the allowed files, and the required test and attaches it to the prompt as `laravel_knowledge`. A task that mentions desktop or mobile also gets `nativephp_knowledge`; one that mentions cleverness or complexity gets `tarpit_knowledge`. To see what a task would get without running it:

```bash
php artisan molly:knowledge:pack 'Validate the queued greeting route.' \
  --file=routes/web.php --test=tests/Feature/GreetingTest.php --json
```

The pack lists the query inputs, the selected concepts, the reason each was chosen, and any empty or unavailable status. It is advisory. It cannot widen the allowed files, change the protected test, or complete a task, and an empty pack does not fail a run.

## Bootstrap graphs for a project

`molly:project-init` and `molly:project-new` build the knowledge graphs for a project in one step. To rebuild them later, or to retry one unit that failed:

```bash
php artisan molly:graphs-bootstrap
php artisan molly:graphs-retry UNIT_ID
```

Unit IDs are in `.molly/graphs/manifest.json`. Exact package versions are cached under `~/.molly/graph-cache` so a second project on the same Laravel version indexes quickly.

## Storage

The graph lives in `.molly/knowledge.sqlite`, read through PDO SQLite. It is disposable: delete it and index again. To use another path:

```dotenv
MOLLY_KNOWLEDGE_DATABASE=.molly/another-name.sqlite
```

## Through MCP

The `molly_knowledge` tool runs the same queries for an MCP client. It never changes the graph. See [Agents and MCP](agents.md#mcp-tools).

## Next

- [Project graph](project-graph.md)
- [Planning](planning.md)
- [Commands](reference/commands.md#laravel-knowledge)

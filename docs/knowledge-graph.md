---
layout: default
title: Local Laravel knowledge
---

# Local Laravel knowledge

Molly can build a small Laravel knowledge graph inside your project. Agents can
use it to find connected documentation and framework source without adding a
hosted graph database or sending the whole manual to a model.

The first slice covers Laravel queues. It connects queue concepts to the pinned
Laravel documentation bundled with Molly and to symbols in the installed
framework. Later slices can add other Laravel chapters and separate namespaces
for Pest, PHP, NativePHP, Super Native, and selected packages.

## Build the graph

Run the index command from your Laravel project:

```bash
php artisan molly:knowledge:index laravel
```

Molly detects the installed Laravel major version. You can state the expected
version when a script should fail on a mismatch:

```bash
php artisan molly:knowledge:index laravel --laravel-version=13
```

The command rebuilds that Laravel version as one snapshot. Running it twice
does not add duplicate nodes or relationships.

## Ask a question

Search for a concept after indexing:

```bash
php artisan molly:knowledge:query Queue
php artisan molly:knowledge:query Retry --depth=1 --limit=10
php artisan molly:knowledge:query Job --relation=uses
```

A query starts with an exact concept or symbol match, then falls back to a
partial name match. It returns connected nodes in a stable order. Depth can be
0 through 3, and the result can contain at most 40 nodes. These limits keep an
agent request small.

Use `--json` for scripts. The `molly_knowledge` MCP tool returns the same
structured result to an agent and does not change the graph.

## Read the provenance

Every returned node and relationship has a `sources` array. A source records:

- its namespace and Laravel version;
- whether it came from documentation or framework source;
- its documentation URL or local source path;
- its pinned revision and SHA-256 digest;
- its documentation retrieval date or PHP symbol when available.

Molly does not report a connection that the indexer did not create. The first
queue index contains direct relationships such as `documented_in`,
`configured_by`, `implements`, `uses`, and `tested_by`.

## Local storage

The default database is `.molly/knowledge.sqlite` in the host application.
`.molly/` should remain in `.gitignore`. Delete the database whenever you want
to rebuild it.

Set another local path with:

```dotenv
MOLLY_KNOWLEDGE_DATABASE=.molly/another-name.sqlite
```

Molly uses PDO SQLite directly. This release does not require Neo4j, a hosted
service, or a Burdgen package.

## Burdgen reuse review

The implementation review covered Burdgen's proposal graph records,
visualizer, and topology projection. They confirmed useful rules for stable
identities, explicit evidence, and bounded output. Those classes depend on
Burdgen-specific proposal and presentation models, so Molly did not copy them.
The graph contracts, SQLite store, indexer, commands, MCP tool, and tests in
this slice are Molly-owned code.

The separate project and blocker graph remains tracked in MME-5219. It can use
these small graph records after this storage contract is reviewed, without
adding project-work concepts to the Laravel queue index.

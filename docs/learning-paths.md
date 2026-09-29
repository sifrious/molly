# Learning paths

Learning paths walk Molly's knowledge and project graphs from a starting concept through connected nodes. Each path follows graph edges, in order, and reports gaps when edges are missing.

Paths are read-only and advisory. They do not change tasks, runs, verification, or approval authority.

## Walk a path in the browser

With the [web interface](web-interface.md) enabled, open `/molly/learn`. The page lists available path templates with their namespace and description. Select a template to walk it step by step.

Each step shows the node, its type, why the path leads there, and source citations. Missing connectivity is listed under Gaps.

## Build a path from application code

`BuildLearningPath` builds paths for any consumer, including Bloom:

```php
$builder = app(\Sifrious\Molly\Actions\BuildLearningPath::class);

// One path
$path = $builder->handle('request-route-through-laravel', version: '12');

// All available paths
$paths = $builder->all(version: '12');

// List templates
$templates = $builder->templates();
```

`handle()` returns a `LearningPathView`. `all()` returns a list.

## Available templates

| Template ID | Namespace | Description |
|---|---|---|
| `architecture-entry` | `laravel` | Walk from the service container through binding resolution to an installed symbol. |
| `request-route-through-laravel` | `laravel` | Follow a route definition through configuration to the framework facade. |
| `task-test-evidence` | `project` | Trace a project task through its acceptance test and run evidence. |
| `package-dependency-usage` | `laravel` | Walk from an Eloquent concept through documentation to the framework symbol. |
| `change-to-evidence` | `project` | Follow a project task through run attempts to verification evidence. |

Templates that require NativePHP graph data are not included. The `nativephp` namespace exists in Molly's knowledge graph, but no path template bridges NativePHP nodes into a learning path yet. A template can be added when the graph supports a connected NativePHP walk.

## Contracts

### LearningPathView

Matches Merry's `LearningPathView` field names.

| Field | Type | Notes |
|---|---|---|
| `id` | string | Path identifier, matches the template ID. |
| `title` | string | Human-readable path title. |
| `description` | string | What the path covers. |
| `repository` | string | Repository name when known. |
| `revision_ref` | string or null | Git revision used for the graph snapshot. |
| `freshness` | string | One of `current`, `stale`, `partially_updated`, `unavailable`, `unknown`. |
| `steps` | list | Ordered `LearningStepView` entries. |
| `error` | string or null | Present when the path cannot be built. |
| `template_id` | string or null | Burdgen-additive. The template that produced this path. |
| `prerequisites` | list | Burdgen-additive. Prerequisite path IDs. |
| `gaps` | list | Burdgen-additive. Descriptions of missing graph connectivity. |
| `complete` | bool | Burdgen-additive. True when all chain steps connected. |

### LearningStepView

| Field | Type | Notes |
|---|---|---|
| `position` | int | 1-indexed position in the path. |
| `total` | int | Total expected steps. |
| `node` | string | Node label. |
| `node_kind` | string | Node type from the graph (concept, configuration, class, etc). |
| `why` | string | Reason this step follows from the previous one. |
| `citations` | list | Source citations in camelCase: `{path, revisionRef, lineStart, lineEnd}`. |
| `next_relationship` | string or null | The graph relationship leading to the next step. |
| `next_node` | string or null | Label of the next node, if known ahead of the walk. |
| `exercise` | string or null | Optional exercise text. |

### Freshness

Lowercase snake_case values matching Merry's freshness enum:

- `current` - the graph data is up to date.
- `stale` - the graph was built from an older snapshot.
- `partially_updated` - some steps connected, but gaps exist.
- `unavailable` - the graph or seed concept is missing.
- `unknown` - freshness could not be determined.

## Serialization

Both views implement `toArray()` and `fromArray()` for JSON transport. `toArray()` output matches Merry's field names. Citation objects use camelCase keys (`path`, `revisionRef`, `lineStart`, `lineEnd`).

## How it works

The path builder queries the graph once from the seed concept with enough depth to cover the template's chain. It then walks edges in order through the result set. When a required edge is missing, the builder records a gap and stops. Steps are never fabricated.

## Limitations

- Paths come from indexed graph relationships only. Exercises are advisory text; they do not modify graph facts.
- Learning completion is advisory. It does not affect task execution, verification, approval, or merge authority.
- NativePHP bridge paths are documented as a gap. Add a template when the graph supports a connected walk.
- The path builder is bounded by the graph query limits (depth 0-3, at most 40 nodes).

## Next

- [Knowledge graph](knowledge-graph.md)
- [Project graph](project-graph.md)
- [Web interface](web-interface.md)

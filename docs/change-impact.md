# Change impact overlay

Molly shows graph-backed change deltas as a read-only overlay. The overlay groups changed items into five sections, matching the Burdgen `graph-delta-view-1` contract and Merry's change-impact screen.

## Sections

| Section             | What it contains                                                      |
|---------------------|-----------------------------------------------------------------------|
| `changed_directly`  | Files and nodes the commit edited.                                    |
| `affected_context`  | Neighbors that reference changed nodes but are not proven broken.     |
| `tests_contracts`   | Tests and contracts that cover the change.                            |
| `unknown_impact`    | Nodes with unproven relationships to the change. Stays unknown.       |
| `visual_changes`    | Blade, Livewire, or other UI files that may look different.           |

Affected context does not mean broken. Unknown impact stays unknown. Molly does not invent authoritative edges.

## View models

Pure serializable PHP classes under `Sifrious\Molly\GraphDelta`:

- `GraphDeltaView` -- top-level delta with version, provenance, and five sections.
- `ChangeItemView` -- one item in a section: id, type, key, change, fields, freshness, verification, source_path, source_url.
- `DeltaProvenance` -- pack_hash, package, exact_version, repository_revision, documentation_revision.
- `Freshness` -- enum: current, stale, partially_updated, unavailable, unknown.
- `VerificationStatus` -- enum: verified, review_required, blocked, unresolved.
- `ChangeType` -- enum: added, removed, changed.
- `Section` -- enum: changed_directly, affected_context, tests_contracts, unknown_impact, visual_changes.

These classes serialize to and from JSON. No Eloquent, no HTTP, no model calls.

## Rendering

```php
use Sifrious\Molly\GraphDelta\GraphDeltaView;

$delta = GraphDeltaView::fromJson($json);
$delta->changedDirectly();   // list of ChangeItemView
$delta->unknownImpact();     // list of ChangeItemView
$delta->toArray();           // round-trips to the same JSON
```

The Livewire component `molly-change-impact` renders the overlay in Molly's local web interface at `/molly/change-impact`. It accepts a `deltaJson` property and renders section tabs, item selection, before/after fields, freshness badges, and verification status without a model call.

## Contract compatibility

Field names and enum values match:

- Burdgen `graph-delta-view-contract.md` (version `graph-delta-view-1`).
- Merry's `GraphDeltaView` / `ChangeItemView` / `Freshness` / `VerificationStatus`.

MME-5305 importance ranking may annotate beside delta items but does not reorder or invent records.

## Fixture

`tests/Fixtures/GraphDelta/role-feature-delta.json` is a complete fixture covering all five sections, multiple freshness and verification states, and before/after field data. The `GraphDeltaViewTest` proves round-trip fidelity against it.

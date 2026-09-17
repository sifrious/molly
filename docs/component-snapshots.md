---
layout: default
title: Component snapshots
---

# Component snapshots

Molly records SHA-256 hashes for the files selected by a task. This lets a run report show which selected component source files changed.

Snapshots do not contain source code and do not render visual previews.

## Example

```bash
php artisan molly:create 'Show a retry button after a failed run.' \
  --name=retry-button \
  --file=app/Livewire/RunStatus.php \
  --file=resources/views/livewire/run-status.blade.php \
  --test=tests/Feature/RunStatusTest.php

php artisan molly:start retry-button
```

Then inspect the run:

```bash
php artisan molly:show RUN_ID --verbose
```

## What Molly recognizes as a component source

| Path | Kind |
| --- | --- |
| `app/Livewire/*.php` | `livewire_class` |
| `app/View/Components/*.php` | `blade_class` |
| `resources/views/livewire/*.blade.php` | `livewire_view` |
| `resources/views/components/*.blade.php` | `blade_component` |
| Other `resources/views/*.blade.php` | `blade_view` |

Nested paths also match.

The stable component ID is `component:` followed by the repository-relative path.

Molly does not resolve Blade aliases or automatically join a Livewire class to its view.

## Snapshot times

Molly can record:

- task creation state
- before-run state
- after-run state

Retries get their own before and after snapshots. Earlier run evidence is not replaced.

## Change labels

| Label | Meaning |
| --- | --- |
| `added` | The selected file did not exist before and exists afterward. |
| `removed` | It existed before and is absent afterward. |
| `modified` | It exists in both snapshots with different content. |
| `unchanged` | It exists in both with the same content hash. |

A source hash change proves only that file content changed. It does not prove a visible UI change.

## No visual previews yet

Preview status is currently `unavailable`. Molly does not render screenshots or visual diffs in this release.

That planned capability should not be confused with the source snapshot feature that exists today.

## Next

- [Verification](verification.md)
- [Manage tasks](tasks.md)

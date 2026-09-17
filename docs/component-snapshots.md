---
layout: default
title: Component snapshots
---

# Component snapshots

Molly records selected source paths and SHA-256 hashes when you save a task. Each attempt adds its own before and after snapshots so you can inspect which component source files changed.

The snapshots contain metadata only. They do not save source contents, render components, or browse a component catalog.

## Capture selected files

Include the Blade or Livewire files that the task may change:

```bash
php artisan molly:create "Show a retry button after a failed run." \
  --name=retry-button \
  --workspace=/path/to/project \
  --file=app/Livewire/RunStatus.php \
  --file=resources/views/livewire/run-status.blade.php \
  --test=tests/Feature/RunStatusTest.php

php artisan molly:start retry-button
```

Molly includes the required test file in the snapshot. It applies the same file count, size, and path restrictions used for execution. Snapshot capture does not scan outside the selected files or add files to the task's editable scope.

Read the result in the web run page or use the run ID printed after execution:

```bash
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
```

The standard terminal report lists component classifications and snapshot availability. Verbose output adds every selected path and hash. The web page presents the same saved data in expandable snapshot tables.

## Stable component IDs

Molly recognizes these source paths:

| Path | Kind |
| --- | --- |
| `app/Livewire/*.php` | `livewire_class` |
| `app/View/Components/*.php` | `blade_class` |
| `resources/views/livewire/*.blade.php` | `livewire_view` |
| `resources/views/components/*.blade.php` | `blade_component` |
| Other `resources/views/*.blade.php` files | `blade_view` |

Each directory match includes nested paths. An ID is `component:` followed by the full repository-relative path, such as `component:resources/views/livewire/run-status.blade.php`. The same path retains its ID when its contents change. Snapshots sort paths so input order does not change the comparison.

These are source identities. Molly does not parse class declarations, resolve component aliases, or join a Livewire class with its view. Other selected files still receive hashes, but their `component` value is `null`. Custom component directories are outside this first implementation.

## Read the comparison

The report has three snapshots:

| Snapshot | When Molly captures it |
| --- | --- |
| Task creation | When the task is saved or imported. This is the original selected context. |
| Before execution | When an attempt starts, before the model proposes changes. |
| After execution | When the attempt finishes, including a failed or stopped attempt when the selected files remain readable. |

The component comparison uses the attempt's before and after captures. If you edit the workspace between saving and starting a task, its creation snapshot remains unchanged and its execution snapshot records the newer contents. Retries create new run snapshots. They do not overwrite earlier evidence.

| Classification | Meaning |
| --- | --- |
| `added` | The path was absent before the attempt and contains a file afterward. |
| `removed` | The file existed before the attempt and is absent afterward. |
| `modified` | The file exists in both captures with different contents. |
| `unchanged` | The file exists in both captures with identical contents. |

A selected path absent in both captures has no component comparison row. Its snapshot entries still show an absent file. A path rename appears as removal and addition when both paths are selected. Removal evidence does not grant the writer permission to delete files. The current proposal format only adds or replaces file contents.

A changed source file does not prove a visible change. An unchanged selected file does not prove that related files or application behavior stayed the same.

## Missing evidence and previews

Each captured snapshot records `preview.status` as `unavailable` and explains that no local preview renderer is configured. Molly has no screenshot or visual comparison in this release. Preview unavailability does not fail a run.

Tasks saved before this feature have no creation snapshot. Standalone `molly:run` executions also have no saved task context. Both can capture before and after evidence for a new run. Historical runs without snapshot data show that the evidence was not recorded.

If Molly cannot read the final selected files, the after snapshot has status `unavailable` with `SNAPSHOT_CAPTURE_FAILED`. Component changes remain unknown. The run cannot complete, and the report preserves the captured before state. A running attempt uses `not_captured` for its after snapshot and `not_compared` for its component comparison until final evidence is available.

## Stored report fields

The nullable `molly_tasks.context_snapshot` column stores the creation snapshot. Run reports copy it into `report.snapshots.task_creation` and store execution captures under `report.snapshots.before` and `report.snapshots.after`.

A captured snapshot contains `version`, `status`, `captured_at`, `files`, and `preview`. Each file has `path`, `sha256`, and `component`. A `null` hash means the validated path was absent when captured. A missing snapshot means Molly has no evidence for that moment. These states are different.

`report.components.status` is `compared`, `not_compared`, or `unavailable`. A completed comparison includes `changes` entries with `id`, `kind`, `path`, `status`, `before_hash`, and `after_hash`. This list includes unchanged components. No file contents appear in the snapshot or component comparison fields.

The implementation uses the existing file validation and change comparison in `src/Workspace.php`. `src/Actions/CreateTask.php` saves task context, and `src/Actions/RunTask.php` records attempt evidence. The snapshot migration is `database/migrations/2026_09_17_070000_add_context_snapshot_to_molly_tasks_table.php`.

# Component snapshots

Molly records a SHA-256 hash of every file a task selects, at creation, before each run, and after it. The run report can then say which selected files changed without you reading the whole diff. When a selected file is a Livewire component or a Blade view, the report also names its kind.

Snapshots store hashes, not source. Optional previews are separate.

## Example

```bash
php artisan molly:create 'Show a retry button after a failed run.' \
  --name=retry-button \
  --file=app/Livewire/RunStatus.php \
  --file=resources/views/livewire/run-status.blade.php \
  --test=tests/Feature/RunStatusTest.php
php artisan molly:start retry-button
php artisan molly:show RUN_ID --verbose
```

The report lists each selected file as `added`, `removed`, `modified`, or `unchanged`. A retry gets its own before and after snapshots; earlier ones stay with the earlier run.

## What counts as a component

| Path | Kind |
| --- | --- |
| `app/Livewire/*.php` | `livewire_class` |
| `app/View/Components/*.php` | `blade_class` |
| `resources/views/livewire/*.blade.php` | `livewire_view` |
| `resources/views/components/*.blade.php` | `blade_component` |
| Other `resources/views/*.blade.php` | `blade_view` |

Nested paths match too. The component ID is `component:` followed by the path. Molly does not resolve Blade aliases or pair a Livewire class with its view; it reports the files you selected.

A changed hash proves the file changed. It does not prove the screen looks different.

## Optional previews

To capture an image before and after, point Molly at a local renderer:

```dotenv
MOLLY_PREVIEW_COMMAND="your-renderer {input} {output}"
MOLLY_PREVIEW_URL="http://127.0.0.1:8000"
MOLLY_PREVIEW_VIEWPORT=1280x720
```

Molly runs the command with `{input}` set to a generated HTML fixture and `{output}` to a PNG path under `.molly/previews/`; `{url}` and `{viewport}` are also available. The report records the viewport, the fixture, the renderer, the source commit when Git is available, and the image digest.

Without a command, the preview status is `unavailable` with a reason. A preview is advisory. It never decides whether a task passes, and a captured image is not proof the UI is right unless the task's test asserts it.

## Next

- [Verification](verification.md)
- [Tutorials](tutorials.md#before-and-after-evidence-for-a-component)

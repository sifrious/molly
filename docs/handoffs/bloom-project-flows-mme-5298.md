# MME-5298 — New Molly project + Add to existing

## Discovery

- `molly:create` / `CreateTask` create tasks, not projects
- `molly:setup` / `ConfigureAgent` configure agents, not projects
- New services: `CreateMollyProject`, `InitializeMollyInExistingProject`, `ListMollyProjects`

## Shared persistence

- `~/.molly/projects.json` — path index (override with `MOLLY_HOME`)
- `<project>/.molly/project.json` — id, name, path, source (`new`|`existing`), created_at

## CLI

- `php artisan molly:project-new {path}`
- `php artisan molly:project-init {path?}`
- `php artisan molly:projects`

## Bloom

Thin UI on plugin host tip/mme-5297-plugin-host; calls same artisan services.

## Tests

`./vendor/bin/pest tests/Feature/MollyProjectFlowsTest.php` — 4 passed.

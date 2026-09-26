# Settings

Molly has two layers of settings. `config/molly.php` belongs to one application and is documented in the [configuration reference](reference/configuration.md). Global defaults shared by every project on your machine live in `~/.molly/settings.json`, or `$MOLLY_HOME/settings.json` when you set `MOLLY_HOME`.

## Read and change global settings

```bash
php artisan molly:settings
php artisan molly:settings-set --patch='{"loop":{"max_iterations":5}}'
```

`molly:settings` prints the documented defaults merged with what you have saved. `molly:settings-set` merges a JSON patch into the saved file. Bloom's Settings screen reads and writes the same file.

## What is in it

| Area | Key | Default |
| --- | --- | --- |
| Graph storage | `graph_storage.project_database` | `.molly/knowledge.sqlite`, relative to the project |
| Graph storage | `graph_storage.exact_version_cache` | `~/.molly/graph-cache` |
| Runtime | `runtime.agent` | `ollama` |
| Runtime | `runtime.model` | `null`; the local model name for Ollama |
| Loop | `loop.max_iterations` | `3` attempts per task |
| Loop | `loop.timeout_seconds` | `180` per model request |
| Loop | `loop.test_timeout_seconds` | `120` for Pest |
| Loop | `loop.retry_limit` | `3` |
| Loop | `loop.continuation` | `retry_on_verification_failure` |
| Stop and success | `stop_success.*` | Pest and Tarpit required |
| Verification | `verification.*` | Pest, Tarpit, and the parallel join required, each with its failure action |
| Knowledge | `knowledge_sources.*` | Laravel, dependency, and project graphs on |

## Runs remember their settings

When a run is created, Molly saves the settings it will use, layered as defaults, then global settings, then the task's own overrides, into the run's `effective_config`. Changing a setting afterward does not change any earlier run, and `molly:inspect --run=RUN_ID` shows the values that applied.

## Next

- [Configuration reference](reference/configuration.md)
- [Inspecting tasks and runs](inspection.md)

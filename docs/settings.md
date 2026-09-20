# Molly settings

Global loop and graph defaults live in `~/.molly/settings.json` (or `$MOLLY_HOME/settings.json`).

Bloom Settings and `php artisan molly:settings` / `molly:settings-set` read and write the same file.

## Documented defaults

| Area | Key | Default | Notes |
| --- | --- | --- | --- |
| Graph storage | `graph_storage.project_database` | `.molly/knowledge.sqlite` | Per-project SQLite relative to the project root |
| Graph storage | `graph_storage.exact_version_cache` | `~/.molly/graph-cache` | Exact package-version graph cache (MME-5299) |
| Runtime | `runtime.agent` | `ollama` | Agent driver |
| Runtime | `runtime.model` | `null` | Local model id when using ollama |
| Loop | `loop.max_iterations` | `3` | Max run attempts per task |
| Loop | `loop.timeout_seconds` | `180` | Agent timeout |
| Loop | `loop.test_timeout_seconds` | `120` | Pest timeout |
| Loop | `loop.retry_limit` | `3` | Verification retry budget |
| Loop | `loop.continuation` | `retry_on_verification_failure` | What happens after a failed check |
| Stop / success | `stop_success.*` | pest+tarpit required | Success gates |
| Verification | `verification.*` | pest/tarpit/parallel_join required | Policy + actions |
| Knowledge | `knowledge_sources.*` | laravel/deps/project on | Which graphs may feed the agent |

## Reproducibility

Every run stores an **immutable** `effective_config` snapshot (defaults ← globals ← task overrides) in `molly_runs.effective_config` when the run is created. Changing global settings does **not** rewrite historical rows.

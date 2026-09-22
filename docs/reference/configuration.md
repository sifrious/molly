---
layout: default
title: Configuration reference
---

# Configuration reference

Use this page when you need to change Molly's defaults or find the environment variable for a setting.

Publish the config files once:

```bash
php artisan vendor:publish --tag=molly-config
```

After changing `.env` or config, run:

```bash
php artisan config:clear
php artisan molly:doctor
```

Restart long-running queue workers after configuration changes.

## Most commonly changed settings

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.agent` | `ollama` | `ollama` or `amp`. Environment: `MOLLY_AGENT`. |
| `molly.model` | `null` | Exact local Ollama model name. Environment: `MOLLY_LOCAL_MODEL`. |
| `molly.timeout` | `180` | Seconds allowed for proposal or review model requests. |
| `molly.test_timeout` | `120` | Seconds allowed for the required Pest test. |
| `molly.parallel_checks` | `true` | Run Pest and Tarpit at the same time. Set false when POSIX process-group support is unavailable. |
| `molly.max_attempts` | `3` | Total attempts allowed for a saved task. Allowed range: 1 through 10. |
| `molly.max_files` | `8` | Maximum writable files. The protected test does not count toward this limit. |
| `molly.verification.pest` | `required` | Pest remains a completion gate. |
| `molly.verification_actions.pest` | `retry` | Retry Pest failures while attempts remain. |
| `molly.sandbox.allow_unsafe` | `false` | Local-only override when Landlock isolation is unavailable. Environment: `MOLLY_SANDBOX_ALLOW_UNSAFE`. |
| `molly.max_file_bytes` | `65536` | Maximum bytes in one selected file or replacement. |
| `molly.ui.enabled` | `false` | Enables local web routes. Environment: `MOLLY_UI_ENABLED`. |
| `molly.ui.prefix` | `molly` | Local web route prefix. |

The task prompt limit is 8,192 bytes. Changing size limits does not expand the allowed directories.

## Laravel knowledge

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.knowledge.database` | `.molly/knowledge.sqlite` | Local SQLite knowledge graph. Environment: `MOLLY_KNOWLEDGE_DATABASE`. |
| `molly.preview.command` | `null` | Local preview renderer. Placeholders: `{input}`, `{output}`, `{url}`, `{viewport}`. Environment: `MOLLY_PREVIEW_COMMAND`. |
| `molly.preview.url` | `null` | Optional workspace URL passed to the renderer. Environment: `MOLLY_PREVIEW_URL`. |
| `molly.preview.viewport` | `1280x720` | Recorded viewport label. Environment: `MOLLY_PREVIEW_VIEWPORT`. |

Keep `.molly/` out of version control. The knowledge database is disposable.

## Ollama

Molly uses Laravel AI's Ollama provider.

| Setting | Default | Purpose |
| --- | --- | --- |
| `ai.providers.ollama.driver` | `ollama` | Must remain the Ollama driver. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Local endpoint. Environment: `OLLAMA_URL`. |

Molly only accepts a loopback HTTP endpoint for this local provider path. The model name must match `ollama list` and must not be a cloud model name.

## Amp

Amp has no Molly API-key setting. The Amp CLI owns its login and model selection.

The `amp` executable must be on the `PATH` for the process running Molly.

Use:

```bash
php artisan molly:setup --agent=amp
```

## Jev and TypeSafe

Jev classification is off by default. Published config sets `molly.jev.enabled` from `MOLLY_JEV_ENABLED` with a `false` default. When the gate is off, Molly opens no classification request, records no TypeSafe/Laravel AI classification provenance, and keeps Pest, Tarpit, retries, and completion on their deterministic paths.

There is one explicit opt-in:

```bash
MOLLY_JEV_ENABLED=true
php artisan config:clear
php artisan molly:doctor
```

Provider credentials and base URL belong to Laravel AI — configure `ai.providers.typesafe` (secret: `TYPESAFE_API_KEY`). Do not set a second Molly HTTP transport. Former `molly.typesafe.*` transport keys (`enabled`, `api_key`, and a fixed Molly-owned endpoint) are removed; Molly no longer publishes them.

Molly policy for enabled Jev stays under `molly.jev`:

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.jev.enabled` | `false` | Single default-off Jev gate. Environment: `MOLLY_JEV_ENABLED`. Non-true values keep every Jev-backed path closed. |
| `molly.jev.model` | `jev-latest` | Evaluator model name passed through Laravel AI. |
| `molly.jev.confidence_threshold` | `0.8` | Lower-confidence results become `needs_review`. |
| `molly.jev.timeout` | `30` | Request timeout, allowed range 1 through 120 seconds. |
| `molly.jev.instructions` | Built-in task advice question | Instructions used for eligible failed-task advice. |

When enabled, Jev can support explicit planning suggestions, task advice, and commit review through Laravel AI classification. It cannot bypass tests, Tarpit blockers, or attempt limits. Configure one provider stack only: Laravel AI TypeSafe plus `molly.jev` policy — never both a Molly `typesafe` client and Laravel AI.

### Laravel AI compatibility

Molly currently requires Laravel AI's `1.x-dev` line so it can use the public classification / `decide` seam merged in [laravel/ai#1049](https://github.com/laravel/ai/pull/1049) before the next tag. The maintainer `composer.lock` records the exact revision proven by Molly's release tests. Move back to a tagged compatible constraint once Laravel publishes one.

`MOLLY_JEV_ENABLED` still defaults to `false`. Enabling it is an explicit opt-in; deterministic Pest, Tarpit, retry and completion gates remain authoritative.

## Complexity measurements

`config/molly-complexity.php` controls Clever measurements.

Main settings:

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly-complexity.enabled` | environment-based | Enabled in local/testing unless explicitly disabled. Production stays disabled. |
| `molly-complexity.root` | host application | Root used by standalone Clever commands. |
| `molly-complexity.report.path` | `storage/molly/complexity/report.json` | Standalone report file. |
| `molly-complexity.probes` | four bundled probes | Measurements run during scans. An empty list cannot complete a Molly task. |
| `molly-complexity.owned_diff.paths` | app, bootstrap, config, database, routes, resources/js | Paths used for owned-code counts and hotspots. |
| `molly-complexity.welds.paths` | app | PHP paths scanned for constructor and static-call sites. |
| `molly-complexity.welds.max_sites` | `200` | Maximum detailed sites retained per list. |
| `molly-complexity.lonely.min_lines` | `30` | Minimum current code lines for a listed single-author file. |
| `molly-complexity.lonely.limit` | `10` | Maximum listed lonely files. |
| `molly-complexity.churn.since` | `24 months ago` | Git history window for hotspots. |
| `molly-complexity.churn.limit` | `20` | Maximum listed hotspots. |
| `molly-complexity.exclude` | `[]` | Extra directory names to skip during source enumeration. |

Default probes are owned diff, welded call sites, lonely files, and hotspots.

Read [Verification](../verification.md#clever-measurements) before comparing measurements.

## Host database and queue

Molly uses the Laravel application's existing database and queue configuration. It does not define separate database credentials.

For web or MCP start/retry requests, supported queue drivers are database, Redis, Beanstalkd, and SQS.

Database, Redis, and Beanstalkd reservation time must exceed Molly's 3600-second queued job timeout. `3700` is a suitable example. SQS visibility timeout must also be above 3600 seconds.

CLI `molly:start` runs in the current terminal and does not need a queue worker.

## Environment variables

| Variable | Meaning |
| --- | --- |
| `MOLLY_AGENT` | `ollama` or `amp` |
| `MOLLY_LOCAL_MODEL` | Installed local Ollama model name |
| `MOLLY_KNOWLEDGE_DATABASE` | Local SQLite graph path |
| `MOLLY_UI_ENABLED` | Enable local web routes |
| `MOLLY_COMPLEXITY_ENABLED` | Optional complexity-measurement override |
| `MOLLY_JEV_ENABLED` | Single default-off Jev gate (`false` unless set true) |
| `TYPESAFE_API_KEY` | Laravel AI TypeSafe provider secret (`ai.providers.typesafe`) |
| `OLLAMA_URL` | Local Ollama endpoint |
| `APP_ENV` | Host Laravel environment, normally `local` for Molly |
| `APP_NAME` | Host application name used in some reports |
| `QUEUE_CONNECTION` | Queue used for web/MCP start and retry |

Timeouts, size limits, attempt limits, parallel-check mode, and the route prefix are changed in the published PHP configuration rather than through Molly-specific environment variables.

## Next

- [Getting started](../getting-started.md)
- [Command reference](commands.md)
- [Troubleshooting](../troubleshooting.md)

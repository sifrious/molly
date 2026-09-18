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

## TypeSafe

TypeSafe is optional and off by default.

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.typesafe.enabled` | `false` | Explicit opt-in for TypeSafe requests. |
| `molly.typesafe.api_key` | `null` | Secret from `TYPESAFE_API_KEY`. Never commit it. |
| `molly.typesafe.model` | `jev-latest` | Requested evaluator model. |
| `molly.typesafe.confidence_threshold` | `0.8` | Lower-confidence results become `needs_review`. |
| `molly.typesafe.timeout` | `30` | Request timeout, allowed range 1 through 120 seconds. |
| `molly.typesafe.instructions` | Built-in task advice question | Instructions used for eligible failed-task advice. |

The endpoint is fixed at `https://api.typesafe.ai/v1/systemone`.

TypeSafe can support explicit planning suggestions, task advice, and commit review. It cannot bypass tests, Tarpit blockers, or attempt limits.

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
| `TYPESAFE_API_KEY` | Secret key for explicitly enabled TypeSafe evaluation |
| `OLLAMA_URL` | Local Ollama endpoint |
| `APP_ENV` | Host Laravel environment, normally `local` for Molly |
| `APP_NAME` | Host application name used in some reports |
| `QUEUE_CONNECTION` | Queue used for web/MCP start and retry |

Timeouts, size limits, attempt limits, parallel-check mode, and the route prefix are changed in the published PHP configuration rather than through Molly-specific environment variables.

## Next

- [Getting started](../getting-started.md)
- [Command reference](commands.md)
- [Troubleshooting](../troubleshooting.md)

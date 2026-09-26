# Configuration

Molly's settings live in `config/molly.php` and `config/molly-complexity.php`, published with:

```bash
php artisan vendor:publish --tag=molly-config
```

The defaults suit a local development project. After changing `.env` or either file:

```bash
php artisan config:clear
php artisan molly:doctor
```

Restart queue workers after configuration changes.

## The settings you are most likely to change

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.agent` | `ollama` | `ollama` or `amp`. Environment: `MOLLY_AGENT`. |
| `molly.model` | `null` | The exact local Ollama model name. Environment: `MOLLY_LOCAL_MODEL`. |
| `molly.timeout` | `180` | Seconds allowed for each model request. |
| `molly.test_timeout` | `120` | Seconds allowed for the required Pest test. |
| `molly.max_attempts` | `3` | Runs a task may make, from 1 to 10. |
| `molly.parallel_checks` | `true` | Run Pest and Tarpit at the same time. Set `false` without POSIX process groups. |
| `molly.max_files` | `8` | Writable files per task. The protected test is not counted. |
| `molly.max_file_bytes` | `65536` | Largest selected file or replacement. |
| `molly.sandbox.allow_unsafe` | `false` | Run without Landlock isolation. Environment: `MOLLY_SANDBOX_ALLOW_UNSAFE`. See [macOS and the sandbox](../getting-started.md#macos-and-the-sandbox). |
| `molly.ui.enabled` | `false` | Enable the local web interface. Environment: `MOLLY_UI_ENABLED`. |
| `molly.ui.prefix` | `molly` | Route prefix for the web interface. |

A task prompt may be up to 8,192 bytes. Raising the size limits does not add directories to the allowed list.

## Verification

Each verifier has a policy and a failure action:

| Setting | Default |
| --- | --- |
| `molly.verification.pest` | `required` |
| `molly.verification.tarpit` | `required` |
| `molly.verification.parallel_join` | `required` |
| `molly.verification.false_green` | `required` |
| `molly.verification_actions.pest` | `retry` |
| `molly.verification_actions.tarpit` | `retry` |
| `molly.verification_actions.parallel_join` | `retry` |
| `molly.verification_actions.false_green` | `fail` |

`retry` lets `molly:retry` make another attempt while attempts remain. `fail` ends the task. Pest and Tarpit stay required in every shipped configuration.

False-green detection is off until you enable it:

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.false_green.enabled` | `false` | Run the mutation probe after Pest passes. Environment: `MOLLY_FALSE_GREEN`. |
| `molly.false_green.max_mutations` | `2` | Files to stub, one at a time. |
| `molly.false_green.timeout_seconds` | `30` | Budget for each probe run. |

[Verification](../verification.md) explains what each verifier checks.

## Ollama

Molly uses Laravel AI's Ollama provider, configured in `config/ai.php`:

| Setting | Default | Purpose |
| --- | --- | --- |
| `ai.providers.ollama.driver` | `ollama` | Leave as is. |
| `ai.providers.ollama.url` | `http://localhost:11434` | Must be a loopback HTTP URL. Environment: `OLLAMA_URL`. |

The model name must match `ollama list` and must not look like a hosted model.

## Amp

Amp has no Molly settings beyond `molly.agent`. The Amp CLI keeps its login and model. `amp` must be on the `PATH` of the process running Molly.

## Jev

Jev is off unless `MOLLY_JEV_ENABLED=true`. When it is off, Molly makes no classification request and records `jev_disabled` wherever advice could have appeared.

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.jev.enabled` | `false` | The single gate. Environment: `MOLLY_JEV_ENABLED`. Anything other than a true value keeps it closed. |
| `molly.jev.model` | `jev-latest` | The TypeSafe model passed through Laravel AI. |
| `molly.jev.confidence_threshold` | `0.8` | Answers below this become `low_confidence` and Molly keeps its own guidance. |
| `molly.jev.timeout` | `30` | Seconds per request, from 1 to 120. |
| `molly.jev.instructions` | the task-advice question | The question asked for failed-task advice. |

The credential belongs to Laravel AI: `ai.providers.typesafe.key`, from `TYPESAFE_API_KEY`. Molly has no TypeSafe client of its own.

### Installing a Laravel AI with classification

Molly's normal install stays on the stable `laravel/ai` release, which has no classification API, so enabling Jev there reports `capability_missing`. Until Laravel tags a release with the classification API, opt your application into the accepted commit (Laravel AI pull request #1049):

```bash
composer require 'laravel/ai:1.x-dev#f0a5d4f3c5bddda7c8975eb79e92d62811197484 as 0.11.99' --with-all-dependencies
php artisan vendor:publish --tag=ai-config --force
php artisan config:clear
php artisan molly:doctor
```

The `as 0.11.99` alias is needed because Molly requires `laravel/ai ^0.11.2` and a bare `1.x-dev` pin does not satisfy that constraint. Republishing `config/ai.php` matters when it was published from the stable release, which has no `typesafe` provider entry; a published file replaces the package's provider list. Once a compatible Laravel AI tag exists, replace the pin with that constraint.

### Jev states

One class decides whether a request may classify, and every transport reports the same result:

| Gate | Laravel AI | Result |
| --- | --- | --- |
| Off (default) | Any | No request. Status `disabled`, reason `jev_disabled`. |
| On | No classification API | No request. Status `unavailable`, reason `capability_missing`. Advice falls back, `molly:review-commit` exits `1`, plan suggestions are refused. Never reported as success. |
| On | Present, key missing or policy invalid | No request. Status `needs_review`, reason `invalid_config`. |
| On | Present and configured | A request to TypeSafe. Status `evaluated` with the choice, confidence, and probabilities recorded. |
| On | The request throws | Status `needs_review`, reason `provider_error`. No payload is kept. |
| On | The answer is malformed | Status `needs_review`, reason `invalid_answer`. |
| On | Confidence below the threshold | Status `needs_review`, reason `low_confidence`, confidence recorded. Exactly at the threshold passes. |
| Any | A deterministic check failed | That failure stands regardless of Jev. |

`molly:doctor` reports the gate as `jev_disabled`, `jev_capability_missing`, `jev_unconfigured`, or `jev_ready`. Jev never changes the provider, model, or execution target on its own, and a Jev answer is advice: it cannot start, retry, or complete a task.

## Knowledge and previews
```bash
composer require 'laravel/ai:1.x-dev#f0a5d4f3c5bddda7c8975eb79e92d62811197484 as 0.11.99' --with-all-dependencies
php artisan vendor:publish --tag=ai-config --force
php artisan config:clear
php artisan molly:doctor
```

The `as 0.11.99` inline alias is required: Molly itself requires `laravel/ai ^0.11.2`, and a bare `1.x-dev` pin does not satisfy that constraint in a consumer, so Composer refuses it. The alias tells Composer to treat the accepted commit as a 0.11 release for constraint resolution only. Republishing `config/ai.php` matters when the file was published from the stable baseline, which has no `typesafe` provider; a published config replaces the package's provider list, so the gate would report `jev_unconfigured` until the provider entry exists.

`molly:doctor` reports the gate as one check: `jev_disabled` (passed, the default), `jev_capability_missing` (failed: enabled on a laravel/ai without the classification surface), `jev_unconfigured` (failed: enabled and capable, but `ai.providers.typesafe.key` is empty), or `jev_ready`. Keys come from the TypeSafe console (`https://console.typesafe.ai/keys`) and are read only through `TYPESAFE_API_KEY`.

Molly's release CI runs a separate Jev lane that resolves exactly that commit, records the resolved commit in the workflow summary, fails (never skips) the live-capability proofs, and then runs the complete package suite. The ordinary PHP/Laravel package matrix stays on stable dependencies. A floating `1.x-dev` branch is not release evidence. Once Laravel publishes a compatible tag, replace the exact commit with that stable constraint and rerun the complete matrix.

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.knowledge.database` | `.molly/knowledge.sqlite` | The local graph file. Environment: `MOLLY_KNOWLEDGE_DATABASE`. |
| `molly.preview.command` | `null` | A local renderer for component previews, with `{input}`, `{output}`, `{url}`, and `{viewport}` placeholders. Environment: `MOLLY_PREVIEW_COMMAND`. |
| `molly.preview.url` | `null` | Passed to the renderer as `{url}`. Environment: `MOLLY_PREVIEW_URL`. |
| `molly.preview.viewport` | `1280x720` | Recorded with each preview. Environment: `MOLLY_PREVIEW_VIEWPORT`. |

## Clever measurements

`config/molly-complexity.php` controls the bundled measurements:

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly-complexity.enabled` | by environment | On in `local` and `testing` unless set `false`; off in production; opt in elsewhere. Environment: `MOLLY_COMPLEXITY_ENABLED`. |
| `molly-complexity.root` | the application | Root for the standalone `clever:*` commands. |
| `molly-complexity.report.path` | `storage/molly/complexity/report.json` | Where the standalone commands write. |
| `molly-complexity.probes` | the four bundled probes | Measurements to run. An empty list cannot complete a task. |
| `molly-complexity.owned_diff.paths` | `app`, `bootstrap`, `config`, `database`, `routes`, `resources/js` | Paths counted as your code. |
| `molly-complexity.owned_diff.extensions` | php, js, ts, jsx, tsx, vue, css, json | File types counted. |
| `molly-complexity.welds.paths` | `app` | Paths scanned for constructor and static calls. |
| `molly-complexity.welds.facades` | `[]` | Extra facade class names to recognize. |
| `molly-complexity.welds.max_sites` | `200` | Sites listed per probe. |
| `molly-complexity.lonely.min_lines` | `30` | Smallest file listed as single-author. |
| `molly-complexity.lonely.limit` | `10` | Files listed. |
| `molly-complexity.churn.since` | `24 months ago` | Git history window for hotspots. |
| `molly-complexity.churn.limit` | `20` | Hotspots listed. |
| `molly-complexity.exclude` | `[]` | Directory names to skip. |

## Database and queue

Molly uses the application's database and queue. It has no connection settings of its own. Starts from the web interface or MCP need the database, Redis, Beanstalkd, or SQS driver with a reservation or visibility timeout above 3600 seconds; [Web interface](../web-interface.md#queue-requirements) has the details. Artisan starts need no worker.

## Environment variables

| Variable | Meaning |
| --- | --- |
| `MOLLY_AGENT` | `ollama` or `amp` |
| `MOLLY_LOCAL_MODEL` | Installed Ollama model name |
| `OLLAMA_URL` | Local Ollama endpoint |
| `MOLLY_SANDBOX_ALLOW_UNSAFE` | Run without the Linux sandbox |
| `MOLLY_UI_ENABLED` | Enable the web interface |
| `MOLLY_FALSE_GREEN` | Enable false-green detection |
| `MOLLY_JEV_ENABLED` | Enable Jev |
| `TYPESAFE_API_KEY` | Laravel AI's TypeSafe credential |
| `MOLLY_KNOWLEDGE_DATABASE` | Graph file path |
| `MOLLY_COMPLEXITY_ENABLED` | Force Clever on or off |
| `MOLLY_PREVIEW_COMMAND`, `MOLLY_PREVIEW_URL`, `MOLLY_PREVIEW_VIEWPORT` | Component previews |
| `MOLLY_HOME` | Where global settings, conversations, and the graph cache live. Defaults to `~/.molly`. |
| `APP_ENV`, `QUEUE_CONNECTION` | The application's environment and queue |

Timeouts, size limits, the attempt limit, parallel checks, and the route prefix are set in the published PHP files, not through environment variables.

## Next

- [Settings](../settings.md) for the global file
- [Commands](commands.md)
- [Troubleshooting](../troubleshooting.md)

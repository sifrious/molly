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
| `molly.timeout` | `180` | Seconds allowed for each model request. A request that runs longer fails with `PROVIDER_TIMEOUT`. |
| `molly.memory.headroom_gb` | `11` | Gigabytes (10^9 bytes) that must stay free beyond an Ollama model's size, both when the fit decision checks total memory and before Molly lets Ollama load the model. See [Memory](#memory). |
| `molly.test_timeout` | `120` | Seconds allowed for the required Pest test. |
| `molly.max_attempts` | `3` | Runs a task may make, from 1 to 10. |
| `molly.repair.per_failure` | `3` | Failed runs with the same failure fingerprint before Molly refuses another attempt with `REPAIR_BUDGET_EXHAUSTED`, from 1 to 10. `molly.max_attempts` still caps the total. |
| `molly.agent_bus.lease_seconds` | `120` | Seconds a claim lasts without renewal, from 30 to 3600. A running task renews its lease at every run step for the longer of this value and the model or Pest timeout plus 30 seconds, so a live run keeps its claim and a crashed one expires. |
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

### Memory

Before each model request from `molly:story`, `molly:start`, and the Tarpit review, Molly compares the configured model with the memory that `molly:preflight` measures, using the same probe. The model's size is the size Ollama reports in `/api/tags`, the number `ollama list` shows. Available memory is the free, inactive, and speculative pages from `vm_stat`.

- When Ollama already holds the model, according to `/api/ps`, the request goes ahead. Loading it again needs no more memory.
- When the model's size plus `molly.memory.headroom_gb` is more than the available memory, Molly refuses with `MODEL_MEMORY_INSUFFICIENT` and never asks Ollama to load the model. `molly:doctor` reports the same comparison as `model_exceeds_memory`.
- When the available memory, the model's size, or the list of loaded models is unknown, Molly does not refuse. Doctor reports `model_memory_unknown` with the status `unknown`, which does not fail doctor.

`molly.memory.headroom_gb` takes a number of gigabytes, 0 or more; 11 is the default, which covers 8 GB for macOS and other applications, 2 GB for Bloom, and 1 GB for Molly. Any other value fails with `MEMORY_HEADROOM_INVALID`, and doctor reports `memory_headroom_invalid`.

The fit decision in `molly:preflight` uses the same rule with total memory in place of available memory: a model fits with headroom when its size plus `molly.memory.headroom_gb` is no more than the Mac's total memory. `Sifrious\Molly\ModelFit\InstallationHeadroom` holds the rule for both. A download must also leave 15% of the destination volume free; that fraction is fixed. See [The fit decision](../ollama-quickstart.md#the-fit-decision).

Molly measures memory on macOS only, so on other systems the check is always unknown. Memory held by another model that Ollama has loaded counts as used. A run never pulls, deletes, unloads, or switches a model to make room; choose a smaller installed model with `php artisan molly:setup` or free memory yourself. `molly:install-model` is the only command that asks Ollama to download a model, and only after you authorize it.

### Local model records

The approved catalogue ships with Molly in `resources/models/catalogue.v1.json` and is not a setting. Molly keeps machine-level model records under `MOLLY_HOME/models`:

| File | Contents |
| --- | --- |
| `readiness.json` | The last readiness check of each model: digest, runtime version, both steps, latency, and memory. A passed record for the approved digest and the pinned runtime makes the fit decision `already_installed`. |
| `install-journal.json` | The last install state of each model, such as `downloading`, `interrupted`, `failed`, `installed`, `ready`, or `not_ready`, with the code of a failure. |
| `install.lock` | Held while `molly:install-model` runs, so a second install stops with `INSTALL_IN_PROGRESS`. |

Molly writes each JSON file to a temporary file and renames it into place. When `MOLLY_HOME` is not writable, the install fails with `MODEL_RECORDS_UNWRITABLE`.

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

### Laravel AI version

Molly requires `laravel/ai ^1.0` and locks `v1.0.0` for its own tests. Laravel AI v1.0.0 (tag commit `101c7ea33cd8569d82570f753fbf38e48b7d3d95`, released 2026-09-23) contains commit `f0a5d4f3c5bddda7c8975eb79e92d62811197484` from Laravel AI pull request #1049, the classification API that Molly previously pinned by commit. No development branch or inline alias is needed.

If your application still pins the old commit or a `0.11.99` alias, replace it with the stable constraint:

```bash
composer require 'laravel/ai:^1.0' --with-all-dependencies
php artisan config:clear
php artisan molly:doctor
```

If you published `config/ai.php` under 0.11, merge the `typesafe` provider from `vendor/laravel/ai/config/ai.php` into your file and keep your other providers. A published file replaces the package's provider list, so the gate reports `jev_unconfigured` until the `typesafe` entry exists. The key comes from `TYPESAFE_API_KEY`; Molly never stores a second credential.

Every CI lane, including the lowest-dependency lane, resolves at least `laravel/ai` 1.0.0 and runs the classification tests without skipping them. See [AI ownership](ai-ownership.md) for the boundary between Laravel AI and Molly's own policy.

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

`php artisan molly:worker start` runs one `php artisan queue:work {connection} --queue={queue}` process for you, using the default queue connection and that connection's `queue` value. Molly jobs are dispatched to the same place, so the worker and the jobs always agree.

| Setting | Default | Purpose |
| --- | --- | --- |
| `molly.worker.php_binary` | `null` | The PHP binary used to run the worker. `null` uses the binary running the Artisan command. A bare name such as `php` is looked up on `PATH`. Environment: `MOLLY_WORKER_PHP_BINARY`. |

The worker's record, `.molly/worker/worker.json`, holds its pid, process group, command line, queue, and start time. Its output goes to `.molly/worker/worker.log`. Molly signals the recorded process only while it is alive, still leads the recorded process group, and still runs the recorded command line. Stopping sends `SIGTERM` to the process group and `SIGKILL` after `--timeout` seconds (30 by default). `queue:work` finishes its current job before it exits on `SIGTERM`, so a `SIGKILL` can interrupt a running task; the task's agent-bus lease then expires and the task can be recovered.

## Environment variables

| Variable | Meaning |
| --- | --- |
| `MOLLY_AGENT` | `ollama` or `amp` |
| `MOLLY_LOCAL_MODEL` | Installed Ollama model name |
| `OLLAMA_URL` | Local Ollama endpoint |
| `OLLAMA_MODELS` | The models directory `molly:preflight` and `molly:install-model` measure when `--destination` is not given |
| `MOLLY_SANDBOX_ALLOW_UNSAFE` | Run without the Linux sandbox |
| `MOLLY_UI_ENABLED` | Enable the web interface |
| `MOLLY_FALSE_GREEN` | Enable false-green detection |
| `MOLLY_JEV_ENABLED` | Enable Jev |
| `TYPESAFE_API_KEY` | Laravel AI's TypeSafe credential |
| `MOLLY_KNOWLEDGE_DATABASE` | Graph file path |
| `MOLLY_COMPLEXITY_ENABLED` | Force Clever on or off |
| `MOLLY_PREVIEW_COMMAND`, `MOLLY_PREVIEW_URL`, `MOLLY_PREVIEW_VIEWPORT` | Component previews |
| `MOLLY_WORKER_PHP_BINARY` | PHP binary for `molly:worker` |
| `MOLLY_HOME` | Where global settings, conversations, the graph cache, and local model records live. Defaults to `~/.molly`. |
| `APP_ENV`, `QUEUE_CONNECTION` | The application's environment and queue |

Timeouts, size limits, the memory headroom, the attempt limit, parallel checks, and the route prefix are set in the published PHP files, not through environment variables.

## Next

- [Settings](../settings.md) for the global file
- [Commands](commands.md)
- [Troubleshooting](../troubleshooting.md)

## Seam instruction packs

Publish `config/molly-seams.php` with `vendor:publish --tag=molly-seam-config`. These keys have no new environment variables.

| Key | Default and purpose | Consumer |
| --- | --- | --- |
| `molly-seams.path` | `resources/molly/seams`, complete application packs relative to the workspace | `src/Seams/InstructionPacks.php` |
| `molly-seams.max_file_bytes` | 262144, maximum instruction file size | `src/Seams/PackFiles.php` |
| `molly-seams.max_pack_bytes` | 2097152, maximum total pack size | `src/Seams/InstructionPacks.php` |
| `molly-seams.handlers` | Empty name-to-class map of trusted `StepHandler` implementations | `src/Seams/SeamSteps.php` |
| `molly-seams.step_timeout` | 300 seconds, verification budget divided between bounded Pest invocations | `src/Actions/ExecuteSeamStep.php` |

Seam policies use the existing `molly.verification.seam_<step>` keys, defaulting to required, and `molly.verification_actions.seam_<step>` failure actions. `src/Actions/DecideRunCompletion.php` resolves them when the revision is created. Ordinary pack edits cannot alter the frozen policy. The persistent queue and sandbox requirements remain in effect. See [Editable seam instructions](../seams.md).

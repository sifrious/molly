# Ollama

Ollama runs a model on your own machine. It is Molly's default agent, it needs no account, and Molly never falls back from it to a hosted service. This page covers the fit decision, installing an approved model, the endpoint, and what doctor tells you when something is off.

## Set it up

Install Ollama 0.34.4 from [ollama.com](https://ollama.com). Then let Molly decide which approved model fits this Mac, install it, and select it:

```bash
php artisan molly:preflight
php artisan molly:install-model
php artisan molly:setup --agent=ollama --model=MODEL
php artisan config:clear
php artisan molly:doctor
```

`molly:preflight` measures the Mac and prints the fit decision. It downloads nothing. `molly:install-model` shows the download size and the volume it goes to, asks before it downloads, verifies what Ollama installed, and runs a readiness check. When it finishes, it prints the `molly:setup` line for the model it installed. `molly:setup` writes `MOLLY_AGENT=ollama` and `MOLLY_LOCAL_MODEL` to `.env`.

Confirm the daemon answers:

```bash
curl -s http://127.0.0.1:11434/api/tags
```

## The fit decision

`molly:preflight` compares this Mac with Molly's approved catalogue and reaches one of these outcomes:

| Status | Meaning |
| --- | --- |
| `recommended_fit` | The model fits and leaves `molly.memory.headroom_gb` of memory for macOS, Bloom, and Molly. |
| `minimum_fit` | The model meets its minimum requirements. The decision lists the constraints, such as `memory_headroom_below_budget`, `memory_pressure_warning`, or `process_translated`. |
| `already_installed` | Ollama lists the approved artifact with the approved digest, and it passed Molly's readiness check on the pinned runtime. |
| `no_fit` | The message is `No supported local Ollama configuration fits this Mac.` The decision lists what Molly measured, what each model needs, and the reason codes. |
| `unsupported` | This is not an Apple silicon Mac running macOS 14.0 or later. The reason is `platform_unsupported:intel`, `platform_unsupported:linux`, or `os_version_unsupported`. |
| `unknown` | Molly could not measure a fact the decision needs, and the reasons name it. Unknown is not a finding that the Mac is incompatible. Molly downloads nothing until the fact is measured. |

Molly selects the largest approved model that fits with headroom. When none does, it selects the smallest model that meets its minimum. A 96 GB Mac Studio gets `gpt-oss:120b-code`, a 24 GB Mac gets `gpt-oss:20b`, a 16 GB Mac gets `gpt-oss:20b` as a `minimum_fit`, and an 8 GB Mac has no fit.

The decision applies these rules:

- A model meets its minimum when total memory is at least the catalogue's minimum: 16 GiB for `gpt-oss:20b` and 96 GiB for `gpt-oss:120b-code`. It fits with headroom when total memory is at least its size plus `molly.memory.headroom_gb`, and at least the catalogue's recommended memory. A run uses the same headroom rule against available memory before Ollama loads a model; see [Memory](reference/configuration.md#memory).
- A `warning` or `critical` memory pressure level downgrades a recommended fit to `minimum_fit`, so a Mac under pressure gets the smaller model. Under `critical` pressure Molly also refuses to install. Pressure is a reading from the moment preflight ran, so the selection can change with load; `molly:install-model MODEL` still installs any approved model that fits.
- When PHP runs under Rosetta translation, `uname -m` reports `x86_64`. Molly reads `hw.optional.arm64` first, so it does not mistake an Apple silicon Mac for an Intel Mac, and it downgrades the fit to `minimum_fit` with `process_translated`, because an Ollama started from the same shell may run translated too.
- A download must leave 15% of the destination volume free, the models directory must be writable, and its volume must be mounted. A model that is already installed needs no disk space.

With `--json`, the snapshot gains a `decision` object. It names every input it used: `snapshot_sha256`, the catalogue digest, the headroom, the readiness records, and the configured model. It also carries its own `decision_sha256`. `molly:preflight --snapshot=FILE --json` decides from a saved `molly:preflight --json` document instead of measuring, so the CLI and the Bloom plugin reach the same decision from the same snapshot.

## The approved catalogue

The catalogue ships in `resources/models/catalogue.v1.json`, with its SHA-256 in `catalogue.v1.sha256` and its JSON Schema in `catalogue.schema.v1.json`. Molly checks the checksum and applies the admission policy again every time it loads the file. The catalogue approves two Ollama artifacts, reviewed on 2026-09-28:

| Model | Manifest digest | Size | How Molly installs it |
| --- | --- | --- | --- |
| `gpt-oss:20b` | `sha256:17052f91a42e97930aa6e28a6c6c06a983e6a58dbb00434885a0cf5313e376f7` | 13.8 GB | Pulls `gpt-oss:20b`. |
| `gpt-oss:120b-code` | `sha256:4dda6ee4a98ead297f4cdeae389ecdc1963ed4cad90b10c8a11e1cc2a7b0fcaf` | 65.4 GB | Pulls `gpt-oss:120b` (`sha256:a951a23b46a1f6093dafee2ea481d634b4e31ac720a8a16f3f91e04f5a40ecd9`) and derives `gpt-oss:120b-code` from it with `num_ctx` 65536 and `temperature` 1, as `ollama create` does from a Modelfile. |

The admission policy approves a model only when a US organization developed it, its source is HTTPS, it has a SHA-256 digest and size, its licence permits local and commercial use, and it names a pinned runtime. `qwen2.5-coder:7b` is in the catalogue as rejected, with the reasons `origin_not_us_developed` and `developer_organization_not_us`. An entry with an unknown origin is never approved, so Molly never downloads it.

The runtime is pinned apart from the models, and the decision reports it in `runtime_selection`, separate from `model_selection`. The status is `verified` when `ollama --version` and `/api/version` both report 0.34.4, `version_mismatch` when either reports another version, and `unverified` when Molly could not read one of them. Molly does not install or update Ollama.

The catalogue is due for review on 2027-03-28. After that date the decision still says what fits, adds the flag `catalogue_stale`, and Molly downloads nothing. A catalogue that fails its checksum, or was written for another policy version, makes the decision `unknown` with `catalogue_integrity_failed` or `catalogue_policy_stale`.

## Install a model

```bash
php artisan molly:install-model [MODEL] [--destination=PATH] [--approve]
```

Without `MODEL`, Molly installs the model the fit decision selected. You may name any approved model that fits, for example `gpt-oss:20b` on a Mac where `gpt-oss:120b-code` is recommended.

Before a download, the command shows the size, the models directory, and the volume that holds it, and asks you to confirm. With `--json` or `--no-interaction`, it stops with `DOWNLOAD_AUTHORIZATION_REQUIRED` and prints the command to run again with `--approve`. When the approved artifact is already installed, or the base of a derived model is, there is nothing to download and nothing to confirm.

After you confirm, Molly:

1. Measures the Mac and decides the fit again, immediately before it starts, and records that recheck as the first event of the result. When the model no longer fits, or the download grew beyond what you authorized, it stops.
2. Takes an install lock, so one install runs at a time.
3. Asks the local Ollama API to pull the model and follows its progress.
4. Checks that `ollama --version` and `/api/version` still report the pinned version, and that `/api/tags` lists the model with the approved manifest digest.
5. Runs the readiness check.

The result is `ready` only after the readiness check passes. The command then prints the `molly:setup` line that selects the model. Molly never selects a model for you.

### The readiness check

The check sends two requests through the same path a task uses. The first is a bounded inference: an arithmetic question with a known answer, limited to 1,024 generated tokens and `molly.timeout` seconds. The second is a representative Molly task: a change proposal that adds `Cart::total()` to a small class, then the seven-check Tarpit review of that change. Molly checks that the proposed file is valid PHP and defines the method. It does not run code the model wrote. The record in `MOLLY_HOME/models/readiness.json` keeps the latency of each step and the memory Ollama reports for the model afterwards. A reply that answers but does not do the task fails the check.

### Install codes

Every refusal and failure exits 1 with one of these codes. The install journal in `MOLLY_HOME/models/install-journal.json` keeps the last state of each model.

| Code | Meaning |
| --- | --- |
| `NO_FIT` | No approved model fits this Mac. The message starts with `No supported local Ollama configuration fits this Mac.` |
| `MODEL_DOES_NOT_FIT` | The model you named does not fit this Mac. |
| `MODEL_FIT_UNKNOWN` | Molly could not measure a fact the decision needs. |
| `PLATFORM_UNSUPPORTED` | This is not an Apple silicon Mac running macOS 14.0 or later. |
| `MODEL_NOT_APPROVED` | The model is not an approved artifact in the catalogue. |
| `CATALOGUE_STALE` | The catalogue is past its review date. |
| `RUNTIME_VERSION_MISMATCH` | Ollama reports a version other than the pinned 0.34.4, before or after the download. |
| `RUNTIME_UNVERIFIED` | Molly could not read the Ollama version from the CLI or the API. |
| `RUNTIME_UNREACHABLE` | The local Ollama API did not answer. |
| `MEMORY_PRESSURE_CRITICAL` | macOS reports critical memory pressure. |
| `INSTALLED_DIGEST_CONFLICT` | A model with the approved name but another digest is installed. Molly does not replace it. |
| `BASE_DIGEST_CONFLICT` | The base of a derived model is installed with another digest. Molly does not replace it. |
| `DESTINATION_VOLUME_MISSING` | The models directory is on a volume that is not mounted. |
| `DESTINATION_READ_ONLY` | The models directory is read-only, found before or during the download. |
| `DESTINATION_REMOVED` | The models directory disappeared during the download. |
| `DISK_INSUFFICIENT` | The download would leave less than 15% of the volume free. |
| `DISK_FULL` | The volume ran out of space during the download. |
| `DOWNLOAD_AUTHORIZATION_REQUIRED` | The download was not authorized. Run the printed command with `--approve`. |
| `DOWNLOAD_NOT_AUTHORIZED` | You declined the download. |
| `DOWNLOAD_OFFLINE` | Ollama could not reach its registry. |
| `DOWNLOAD_INTERRUPTED` | The download stopped before Ollama reported success. Run the command again: Ollama resumes from the part it kept, and the result reports `"resumed": true`. |
| `DIGEST_MISMATCH` | Ollama rejected a corrupt part, or it lists the model with a digest other than the approved one. Molly does not mark it ready. |
| `DOWNLOAD_FAILED` | Ollama reported another error. The message quotes its first line. |
| `CREATE_FAILED` | Ollama did not derive the model from its base. |
| `INSTALL_IN_PROGRESS` | Another `molly:install-model` holds the lock. |
| `READINESS_FAILED` | The model is installed with the approved digest, but the readiness check failed. The status is `installed_not_ready`. |

## Models Molly did not install

The decision lists every model Ollama has, with its admission: `approved`, `digest_mismatch`, `build_input` for the `gpt-oss:120b` base of `gpt-oss:120b-code`, `rejected` with the policy's reasons, or `not_catalogued`. Molly never deletes, replaces, or selects any of them. You can still choose an installed model that is not approved with `molly:setup --model=NAME`, as before. The decision then adds the flag `configured_model_not_approved`.

## Choosing a model

A model that appears in `ollama list` is installed; that does not mean it can complete a Molly task. Two parts of a real task are harder than the demo:

- Writing a Pest test file, when you ask Molly to author the test. The model must return a complete PHP file with `it()` or `test()` cases. Small models often return a PHPUnit class or leave out `<?php`, and Molly rejects those as `TEST_AUTHORING_INVALID`.
- The Tarpit review, which needs a complete, consistent answer to seven questions. Small models often return `REVIEW_INVALID`, and Molly treats that as missing evidence, not a clean review.

In a recorded run on an M3 Ultra, `qwen2.5-coder:7b` failed both across five attempts while `gpt-oss:120b-code` completed the same story on the first try. This is why the readiness check includes a change proposal and a Tarpit review, and why the fit decision prefers the larger approved model when it fits.

Molly never switches models on its own. Before Ollama loads a model it does not already hold, Molly checks that the model's size plus `molly.memory.headroom_gb` (11 GB by default) fits in the available memory, and refuses with `MODEL_MEMORY_INSUFFICIENT` when it does not. See [Memory](reference/configuration.md#memory).

## Limits

- The fit decision and the installer support Apple silicon Macs only. Other hosts get `unsupported`.
- Molly does not install Ollama, update it, or check its code signature. It compares the versions the CLI and the API report with the pinned version.
- Ollama stores models where its own server's `OLLAMA_MODELS` points. Molly measures the directory from `--destination`, then `OLLAMA_MODELS` in Molly's environment, then `~/.ollama/models`. When the Ollama app uses another directory, pass it with `--destination`.
- The readiness check does not run the proposed code or a Pest test. It checks that the file is valid PHP and defines the method, and that the review is complete.
- The approved `gpt-oss:120b-code` digest was recorded on a Mac where the model already existed. Molly has not derived it on a clean machine yet. When the derived model has another digest, the install stops with `DIGEST_MISMATCH`.
- `--approve` authorizes the download that the same run shows. It is not tied to a digest across separate runs.

## The endpoint

Molly talks to Ollama through Laravel AI's Ollama provider. The default endpoint is `http://127.0.0.1:11434`; `localhost` and `[::1]` also count as local. Override it with `OLLAMA_URL` if Ollama listens elsewhere on the same machine.

Molly refuses a non-loopback URL for this path. Pointing Molly at Ollama on another host or an Orb is not a supported setting. Remote execution is not shipped; see [Execution targets](execution-targets.md).

## Doctor codes

`molly:doctor` reports the Ollama setup as several checks so you can tell the cases apart:

| Code | Meaning | What to do |
| --- | --- | --- |
| `ollama_configured` | The agent is Ollama and a model name is set. | Nothing. |
| `ollama_endpoint` | Shows the configured base URL. | Nothing. |
| `ollama_reachable` | Ollama answered `/api/tags`. | Nothing. |
| `ollama_unreachable` | The connection failed. | Start Ollama with `ollama serve` or fix `OLLAMA_URL`. |
| `model_ready` | The configured model is installed. | Nothing. |
| `model_missing` | Ollama is up, but the model is not pulled. | `molly:install-model NAME` for an approved model, `ollama pull NAME` for another one, or fix `MOLLY_LOCAL_MODEL`. |
| `model_fits_memory` | The model's size plus `molly.memory.headroom_gb` fits in the available memory. | Nothing. |
| `model_loaded` | Ollama already holds the model in memory. | Nothing. |
| `model_exceeds_memory` | The model's size plus the headroom is more than the available memory. A run refuses it with `MODEL_MEMORY_INSUFFICIENT`. | Choose a smaller installed model, or free memory. |
| `model_memory_unknown` | Molly could not measure the available memory, the model's size, or the loaded models. The status is `unknown`, and doctor does not fail on it. | Nothing required. `molly:preflight` shows which fact is unknown. |
| `memory_headroom_invalid` | `molly.memory.headroom_gb` is not a number, 0 or more. | Fix the value in `config/molly.php`. |
| `model_not_configured` | No model name is set. | Run `molly:setup --agent=ollama --model=NAME`. |
| `model_not_local` | The name looks like a hosted model. | Choose an installed local model. |
| `ollama_config_invalid` | The driver, URL, timeout, or model name is not usable. | Use loopback HTTP, the Ollama driver, and a positive `molly.timeout`. |

Doctor never prints API keys or account details.

## Timeouts

`molly.timeout` in `config/molly.php` allows 180 seconds per model request by default. A slow model hitting that limit fails with `PROVIDER_TIMEOUT`, which names the model and the timeout, rather than waiting. A refused connection is a different failure, `PROVIDER_UNREACHABLE`. Prefer a smaller task or a faster model before raising the timeout; if you do raise it, clear the configuration cache and run doctor again.

## Common problems

| Symptom | Likely code | Fix |
| --- | --- | --- |
| Connection refused | `ollama_unreachable` in doctor, `PROVIDER_UNREACHABLE` in a run | `ollama serve`, then check the URL. |
| The model takes longer than `molly.timeout` | `PROVIDER_TIMEOUT` | A smaller task or a faster model, or a longer timeout. |
| Model name unknown | `model_missing` in doctor, `MODEL_MISSING` in a run | `molly:install-model NAME` for an approved model, or `ollama pull NAME`. |
| The model is larger than free memory | `model_exceeds_memory` in doctor, `MODEL_MEMORY_INSUFFICIENT` in a run | A smaller model or more free memory. `molly:preflight` says which approved model fits. |
| Out of memory or context errors after the model loads | none (runtime) | A smaller model or more free memory. |
| Ollama answers with an HTTP error | `PROVIDER_ERROR` | Read the Ollama server log, fix the cause, and try again. |
| Ollama's reply is not a chat response | `PROVIDER_RESPONSE_INVALID` | Check that `OLLAMA_URL` points at Ollama itself. |
| The model returns malformed changes | `GENERATION_INVALID` in the run | Retry once; if it repeats, use a larger model. |
| The review keeps coming back `REVIEW_INVALID` | in the run | Use a larger model. |

[Troubleshooting](troubleshooting.md) covers the rest.

## Next

- [Getting started](getting-started.md)
- [Agents](agents.md) for Amp and MCP
- [Configuration](reference/configuration.md#ollama)

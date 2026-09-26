# Ollama

Ollama runs a model on your own machine. It is Molly's default agent, it needs no account, and Molly never falls back from it to a hosted service. This page covers choosing a model, the endpoint, and what doctor tells you when something is off.

## Set it up

Install Ollama from [ollama.com](https://ollama.com), pull a model, and give Molly its exact name:

```bash
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
```

`molly:setup` writes `MOLLY_AGENT=ollama` and `MOLLY_LOCAL_MODEL=qwen2.5-coder:7b` to `.env`. Use the name exactly as `ollama list` prints it.

Confirm the daemon answers:

```bash
curl -s http://127.0.0.1:11434/api/tags
```

## Choosing a model

Any installed model works with `molly:setup --model=NAME`. A model that appears in `ollama list` is installed; that does not mean it can complete a Molly task.

The demo task is small enough for a 7B coder model. Two parts of a real task are harder:

- Writing a Pest test file, when you ask Molly to author the test. The model must return a complete PHP file with `it()` or `test()` cases. Small models often return a PHPUnit class or leave out `<?php`, and Molly rejects those as `TEST_AUTHORING_INVALID`.
- The Tarpit review, which needs a complete, consistent answer to seven questions. Small models often return `REVIEW_INVALID`, and Molly treats that as missing evidence, not a clean review.

In a recorded run on an M3 Ultra, `qwen2.5-coder:7b` failed both across five attempts while `gpt-oss:120b-code` completed the same story on the first try. If you see those errors repeatedly, choose a larger model:

```bash
ollama pull OTHER_MODEL
php artisan molly:setup --agent=ollama --model=OTHER_MODEL
php artisan config:clear
php artisan molly:doctor
```

Molly never switches models on its own. Small coder models want about 8 GB of free memory; larger ones need proportionally more.
Doctor prints a **Code** column so failures stay distinguishable:

| Code | Meaning |
| --- | --- |
| `ollama_configured` / `ollama_endpoint` | Local provider + loopback URL look valid |
| `ollama_reachable` | Ollama answered `/api/tags` |
| `model_ready` | Configured model is installed |
| `model_missing` | Ollama is up, but that model is not pulled |
| `ollama_unreachable` | Connection failed — start Ollama or fix the URL |
| `ollama_config_invalid` | Driver/URL/timeout/model shape refused for local QuickStart |
| `model_not_configured` | No `MOLLY_LOCAL_MODEL` yet |
| `model_not_local` | Model name looks like a cloud/hosted id |
| `jev_disabled` / `jev_ready` | Optional Jev gate is off (default) or enabled and usable; `jev_capability_missing` / `jev_unconfigured` explain an enabled gate that cannot classify |

Doctor never prints API keys or account secrets.

## The endpoint

Molly talks to Ollama through Laravel AI's Ollama provider. The default endpoint is `http://127.0.0.1:11434`; `localhost` and `[::1]` also count as local. Override it with `OLLAMA_URL` if Ollama listens elsewhere on the same machine.

Molly refuses a non-loopback URL for this path. Pointing Molly at Ollama on another host or an Orb is planned work, described in [Execution targets](execution-targets.md), and is not a supported setting today.

## Doctor codes

`molly:doctor` reports the Ollama setup as several checks so you can tell the cases apart:

| Code | Meaning | What to do |
| --- | --- | --- |
| `ollama_configured` | The agent is Ollama and a model name is set. | Nothing. |
| `ollama_endpoint` | Shows the configured base URL. | Nothing. |
| `ollama_reachable` | Ollama answered `/api/tags`. | Nothing. |
| `ollama_unreachable` | The connection failed. | Start Ollama with `ollama serve` or fix `OLLAMA_URL`. |
| `model_ready` | The configured model is installed. | Nothing. |
| `model_missing` | Ollama is up, but the model is not pulled. | `ollama pull NAME`, or fix `MOLLY_LOCAL_MODEL`. |
| `model_not_configured` | No model name is set. | Run `molly:setup --agent=ollama --model=NAME`. |
| `model_not_local` | The name looks like a hosted model. | Choose an installed local model. |
| `ollama_config_invalid` | The driver, URL, timeout, or model name is not usable. | Use loopback HTTP, the Ollama driver, and a positive `molly.timeout`. |

Doctor never prints API keys or account details.

## Timeouts

`molly.timeout` in `config/molly.php` allows 180 seconds per model request by default. A slow model hitting that limit fails the run with a timeout rather than waiting. Prefer a smaller task or a faster model before raising it; if you do raise it, clear the configuration cache and run doctor again.

## Common problems

| Symptom | Likely code | Fix |
| --- | --- | --- |
| Connection refused | `ollama_unreachable` | `ollama serve`, then check the URL. |
| Model name unknown | `model_missing` | `ollama pull NAME`. |
| Out of memory or context errors | none (runtime) | A smaller model or more free memory. |
| The model returns malformed changes | `GENERATION_INVALID` in the run | Retry once; if it repeats, use a larger model. |
| The review keeps coming back `REVIEW_INVALID` | in the run | Use a larger model. |

[Troubleshooting](troubleshooting.md) covers the rest.

## Next

- [Getting started](getting-started.md)
- [Agents](agents.md) for Amp and MCP
- [Configuration](reference/configuration.md#ollama)

---
layout: default
title: QuickStart — Ollama
---

# QuickStart: Ollama

Use this page for the details of running Molly against a **local** model with **no paid AI account**. The shortest path is the [standalone QuickStart](quickstart-standalone.md), which uses the same commands. Bloom is not required.

Ollama is Molly’s recommended local default. It is not a hard dependency. Amp and hosted providers stay optional and never run unless you configure them.

Molly orchestrates the task and verification. Laravel AI talks to Ollama. Bloom (optional) is only the workspace/UI host. Remote Orb execution is planned, not shipped. See [Execution targets](execution-targets.md). This QuickStart uses loopback Ollama on your machine.

## Copy-paste first run

From a Laravel 12 or 13 app with Pest installed (see [Compatibility](compatibility.md)):

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

`qwen2.5-coder:7b` is a **starter suggestion**, not a contract. Any installed local Ollama model name works. Set `MOLLY_LOCAL_MODEL` or pass `--model=` to choose another.

### Hardware note

Small coder models (about 7B parameters) typically need on the order of **8 GB RAM** free for comfortable local use. Larger models need more memory and are slower. If Ollama fails with memory or context errors, pull a smaller tag or close other apps — Molly does not invent fallbacks to hosted APIs.

## 1. Install Ollama

Install from [https://ollama.com](https://ollama.com), then confirm the daemon answers on loopback:

```bash
ollama --version
curl -s http://127.0.0.1:11434/api/tags
```

Default base URL for Molly / Laravel AI: `http://127.0.0.1:11434` (also `localhost` / `[::1]`). Non-loopback URLs are refused for the local QuickStart path.

## 2. Pull a starter model

```bash
ollama pull qwen2.5-coder:7b
ollama list
```

Use the **exact** name from `ollama list` in Molly config.

## 3. Configure Molly / Laravel AI

```bash
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
```

That sets `MOLLY_AGENT=ollama` and `MOLLY_LOCAL_MODEL=...` without requiring an Ollama API key for local HTTP. Endpoint override (still loopback for QuickStart): `ai.providers.ollama.url` / `OLLAMA_URL`.

## 4. Run doctor

```bash
php artisan molly:doctor
php artisan molly:doctor --json
```

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

Doctor never prints API keys or account secrets.

## 5. First Molly task

```bash
php artisan molly:demo
php artisan molly:start demo-greeting
```

Or create your own:

```bash
php artisan molly:create
php artisan molly:start TASK
```

## 6. Inspect output

```bash
php artisan molly:task demo-greeting --json
php artisan molly:show RUN_ID --verbose
php artisan molly:receipt RUN_ID
```

## 7. Change the local model

```bash
ollama pull OTHER_LOCAL_MODEL
php artisan molly:setup --agent=ollama --model=OTHER_LOCAL_MODEL
php artisan config:clear
php artisan molly:doctor
```

Per-run model override stays a config/profile concern — do not edit loop code to switch models.

## 8. Ollama on an Orb or another machine

The supported QuickStart is **loopback**. Pointing Molly at Ollama on another host or an Orb is policy/config work (LAN URL, Orb capability ads) and is planned work described in [Execution targets](execution-targets.md). Do not weaken the loopback safety check for casual remote URLs.

## 9. Troubleshoot

| Symptom | Likely code | Fix |
| --- | --- | --- |
| Connection refused | `ollama_unreachable` | `ollama serve`; confirm `http://127.0.0.1:11434` |
| Model name unknown | `model_missing` | `ollama pull …` — not the same as unreachable |
| Bad URL / HTTPS / non-loopback | `ollama_config_invalid` | Use loopback HTTP for local QuickStart |
| Empty model env | `model_not_configured` | `molly:setup --agent=ollama --model=…` |
| OOM / context length | (runtime) | Smaller model or more RAM |
| Timeout | `test_timeout` / Molly timeout | Raise `molly.timeout` carefully; check model speed |
| Structured-output / tool quirks | (provider) | Prefer a coder model known to return JSON; Molly applies edits itself |

Also see [Troubleshooting](troubleshooting.md).

## 10. Opt-in hosted fallback

Molly **does not** silently fall back to a paid provider when Ollama fails. Hosted / Amp paths require explicit `molly:setup --agent=amp` (or equivalent profile policy). If Ollama is down, doctor fails closed until you fix local readiness or you intentionally switch agents.

## 11. Optional Jev classification (advanced; off by default)

Local agent execution does **not** require a hosted credential and does **not** enable Laravel AI classification / TypeSafe. `MOLLY_JEV_ENABLED` defaults to `false`, so Molly opens no classification request during this QuickStart.

Keep that gate off for the local path. Enabling Jev is a separate advanced step (`MOLLY_JEV_ENABLED=true` plus Laravel AI TypeSafe credentials) documented in [Configuration](reference/configuration.md#jev-and-typesafe). Jev stays advisory: it cannot override Pest, Tarpit, bounded retries, or completion authority.

## Boundaries (short)

- **Molly** — task scope, Pest/Tarpit verification, receipts, completion gate.
- **Laravel AI** — provider transport to Ollama (and optional classification when Jev is explicitly enabled).
- **Bloom** — optional desktop/workspace host, not the model.
- **Orb** — planned remote execution target. Not shipped.
- **Jev / TypeSafe** — optional advanced classification path; off by default and not part of QuickStart.

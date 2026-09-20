---
layout: default
title: v0.1 Friction — Ollama QuickStart
---

# v0.1 Friction notes (Ollama QuickStart)

Known sharp edges for tonight’s local Ollama path. Pair with [QUICKSTART](QUICKSTART.md) and [Troubleshooting](../troubleshooting.md).

## Doctor: unreachable vs missing model

| Code | What it means | Fix |
| --- | --- | --- |
| `ollama_unreachable` | Molly could not reach the Ollama HTTP endpoint | Start Ollama (`ollama serve`), confirm `curl -s http://127.0.0.1:11434/api/tags`, fix `OLLAMA_URL` / Laravel AI Ollama URL |
| `model_missing` | Ollama answered, but the configured model is not installed | `ollama pull <model>` using the exact name from `ollama list`; fix `MOLLY_LOCAL_MODEL` |
| `model_not_configured` | No local model chosen yet | `php artisan molly:setup --agent=ollama --model=…` |
| `model_not_local` / cloud-looking name | Config looks like a hosted model id | Choose a local Ollama tag |
| `ollama_config_invalid` | Driver / URL / timeout shape refused for local QuickStart | Loopback HTTP only (`127.0.0.1` / `localhost` / `[::1]`), Ollama driver, positive timeout |

Unreachable and missing model are **different**. Do not treat connection refused as “pull harder.”

Doctor never prints API keys or account secrets.

## Install friction

- No Packagist tag yet — use Composer VCS + `sifrious/molly:dev-main`.
- Need PHP 8.3+, Laravel 12/13, Pest 4, working DB before `molly:doctor` can go green.
- After `.env` / config edits: `php artisan config:clear` then `molly:doctor`.

## Runtime friction

- **Memory / OOM**: drop to a smaller local tag or free RAM; Molly will not invent a hosted fallback.
- **Timeouts**: slow local models may need a higher `molly.timeout`; shrink the task first.
- **Structured output / tools**: prefer a coder model; Molly still applies and verifies edits itself.
- **Orb / LAN Ollama**: out of tonight’s MVP. Local QuickStart stays loopback; see execution-target docs as they land.
- **Hosted fallback**: opt-in only via explicit agent/profile setup (for example Amp). Failure of Ollama fails closed.

## Out of scope tonight

Full Orb multi-target parallel proof, hosted-fallback matrix fixtures, and Packagist publish are deferred.

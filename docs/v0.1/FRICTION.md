---
layout: default
title: v0.1 Friction — Ollama QuickStart
---

# v0.1 Friction notes (Ollama QuickStart)

> **Historical.** This page records work from the v0.1 release. It is kept for context and is not current install guidance. For today's install steps, read [Getting started](../getting-started.md).

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

- Packagist listing may lag — until listed, use Composer VCS + tagged `sifrious/molly:^0.1.1` (never `dev-main` for release proofs).
- Need PHP 8.3+, Laravel 12/13, Pest, working DB before `molly:doctor` can go green.
- After `.env` / config edits: `php artisan config:clear` then `molly:doctor`.

## Runtime friction

- **Memory / OOM**: drop to a smaller local tag or free RAM; Molly will not invent a hosted fallback.
- **Timeouts**: slow local models may need a higher `molly.timeout`; shrink the task first.
- **Structured output / tools**: prefer a coder model; Molly still applies and verifies edits itself.
- **Orb / LAN Ollama**: out of tonight’s MVP. Local QuickStart stays loopback; see execution-target docs as they land.
- **Hosted fallback**: opt-in only via explicit agent/profile setup (for example Amp). Failure of Ollama fails closed.

## 2026-09-20 — Phase 7 fresh-install (Mac Studio)

Dated notes from MME-5350 acceptance on Mary’s Mac Studio (`molly-v01-fresh` / Herd PHP 8.4).

### Pest wording vs what installers ship

- Older QuickStart copy said **Pest 4**. On Laravel 13 with Herd, `laravel new … --pest` can install **Pest 5.x** (observed Pest 5.2.1).
- Plain `composer create-project laravel/laravel` ships **PHPUnit only**. Add Pest yourself, then init:
  ```bash
  composer require pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --dev --with-all-dependencies
  ./vendor/bin/pest --init   # may prompt to star Pest on GitHub (answer no for non-interactive / CI)
  ```
  Pinning `^4` still resolves Pest 4.x today. `laravel new … --pest` on Laravel 13/Herd may instead ship **Pest 5.x** (observed 5.2.1) — both satisfy Molly’s `pest_ready` check (presence, not a hard-coded major).
- `php artisan pest:install` is not defined on Pest 4/5 Laravel plugin installs; use `./vendor/bin/pest --init`.

### macOS / hosts without Landlock + namespaces

| Code / message | When |
| --- | --- |
| Doctor `sandbox_unavailable` | Host cannot supply writer/verifier sandbox (no Landlock + user/network namespaces). Common on macOS. |
| Start: `SANDBOX_UNAVAILABLE: Molly cannot isolate the writer and Pest verifier on this host. Do not run the safe workflow here.` | `molly:start` after doctor fails the sandbox check. |
| Doctor `sandbox_unsafe_override` | You explicitly opted into unsafe mode (below). |

**Default QuickStart stays safe-sandbox.** Do **not** set the override for untrusted repos or shared machines.

Trusted local Studio recovery only (proof / known-good machine):

```bash
# .env
MOLLY_SANDBOX_ALLOW_UNSAFE=1
php artisan config:clear
php artisan molly:doctor   # expect sandbox_unsafe_override, not sandbox_unavailable
php artisan molly:start demo-greeting
```

Config key: `molly.sandbox.allow_unsafe` (env `MOLLY_SANDBOX_ALLOW_UNSAFE`, truthy). Opt-in only; omitted from the default copy-paste block on purpose.

If a prior `molly:start` failed (for example sandbox), the task may be `failed` and `molly:start` returns `TASK_NOT_PENDING`. Use `php artisan molly:retry TASK_NAME` after enabling the override — do not hand-edit the demo greeting.

## Out of scope tonight

Full Orb multi-target parallel proof, hosted-fallback matrix fixtures, and Packagist publish are deferred.

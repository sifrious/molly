---
layout: default
title: v0.1 QuickStart — Ollama
---

# v0.1 QuickStart: Ollama

> **Historical.** This page records work from the v0.1 release. It is kept for context and is not current install guidance. For today's install steps, read [Getting started](../getting-started.md).

Tonight’s Molly v0.1 local path: **Ollama on loopback**, no paid AI account.

Longer narrative: [QuickStart — Ollama](../ollama-quickstart.md). Friction notes: [FRICTION](FRICTION.md). Screenshot walk-through: [WALKTHROUGH](WALKTHROUGH.md).

## Copy-paste

From a Laravel 12/13 app with Pest and a working database (`laravel new --pest` may ship Pest 5.x on Laravel 13):

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

Or create your own task:

```bash
php artisan molly:create
php artisan molly:start TASK_NAME
```

## Notes

- Prefer tagged `^0.1.1`. Until Packagist lists the package: `composer config repositories.molly vcs https://github.com/sifrious/molly` then require the tag.
- `qwen2.5-coder:7b` is a starter suggestion (~8 GB RAM class), not a hard-coded contract. Override with `--model=` / `MOLLY_LOCAL_MODEL`.
- Default Ollama URL: `http://127.0.0.1:11434`. Local QuickStart refuses non-loopback / HTTPS Ollama URLs.
- No Ollama API key is required for local HTTP.
- Molly does **not** silently fall back to a hosted provider when Ollama fails.
- Doctor codes that matter tonight: `ollama_unreachable` (daemon/URL) vs `model_missing` (daemon up, model not pulled).
- Hosts without a Landlock sandbox (including macOS) fail doctor with `sandbox_unavailable` — see [FRICTION](FRICTION.md). Default QuickStart does **not** enable `MOLLY_SANDBOX_ALLOW_UNSAFE`.

## Next

1. Read task evidence: `php artisan molly:task demo-greeting` / `php artisan molly:show RUN_ID --verbose`
2. Change model: pull another local tag, re-run `molly:setup --agent=ollama --model=…`, then `molly:doctor`
3. If doctor fails, see [FRICTION](FRICTION.md) and [Troubleshooting](../troubleshooting.md)
4. UI screenshots: [WALKTHROUGH](WALKTHROUGH.md) (`./bin/molly-docs-walkthrough`)

## Architecture notes

- [Stacks boundary map](STACKS-BOUNDARY.md) (MME-5352)
- [Identity internalization](IDENTITY-INTERNALIZATION.md)

---
layout: default
title: Agents and MCP
---

# Agents and MCP

Use this page to choose Amp or Ollama, open Molly's local MCP server, or understand which MCP tools are available.

You do not need MCP to run normal Molly tasks from Artisan.

## Choose a provider

Molly supports two providers for code proposals and Tarpit review:

- **Ollama** for a model running on your own machine.
- **Amp** through the Amp CLI and your Amp account.

Both providers use the same allowed-file checks and required verification.

Molly does not silently switch providers when one fails.

## Set up Ollama

For the full copy-paste QuickStart (install → doctor → first task → troubleshoot), see [QuickStart: Ollama](ollama-quickstart.md).

Start Ollama and install a model, then save the exact model name:

```bash
ollama list
php artisan molly:setup --agent=ollama --model=YOUR_INSTALLED_MODEL
php artisan config:clear
php artisan molly:doctor
```

The endpoint must be local. The default is `http://127.0.0.1:11434`.

## Set up Amp

```bash
php artisan molly:setup --agent=amp
amp mcp approve molly
amp mcp doctor molly
php artisan config:clear
php artisan molly:doctor
```

Amp manages its credentials and model choice.

For Molly's code proposal and review requests, the Amp path disables tools, MCP access, IDE access, and remote thread creation. Molly applies the returned edits itself and runs Pest locally.

## Open an interactive Amp chat with Molly tools

```bash
php artisan molly:chat
```

This opens Amp with Molly's local MCP server available. It does not change the provider selected for task execution.

To inspect the command without opening Amp:

```bash
php artisan molly:chat --json
```

## Connect another MCP client

Run Molly as a local stdio MCP server from the Laravel project:

```bash
php artisan mcp:start molly
```

The server is registered only in `local` and `testing` environments. Molly does not expose an HTTP MCP route.

## MCP tools

| Tool | What it does |
| --- | --- |
| `molly_guide` | Reads Molly's bundled planning guide and cited source passages. |
| `molly_knowledge` | Reads the local Laravel, NativePHP, or tarpit knowledge graph. It never changes the graph. |
| `molly_plan` | Creates, reads, answers, or reviews a saved plan. |
| `molly_task` | Creates and manages saved tasks, names, thread links, advice, GitHub comments, human approval, locked Pest tests, recorded pull requests, merge SHAs, handoff envelopes, and lifecycle requests. Show returns display status and recorded GitHub URLs. Approve, lock_test, pr_opened, and merged require `approve=true` and do not open or merge a pull request. `lock_test` freezes the Pest digest after a test-authoring task. |
| `molly_connections` | Reads saved Amp thread associations and optional current Amp connection observations. |

Task start and retry requests from MCP use the host application's queue. A queued request means Molly dispatched work. It does not mean the run already completed.

## Laravel knowledge through MCP

Build the graph first:

```bash
php artisan molly:knowledge:index laravel
php artisan molly:knowledge:index nativephp
php artisan molly:knowledge:index tarpit
```

Then `molly_knowledge` can return a bounded, version-matched Laravel, NativePHP, or tarpit neighborhood with source provenance. NativePHP queries need `namespace=nativephp` and `version=desktop-2` or `mobile-4`. Tarpit queries need `namespace=tarpit`.

Molly also attaches a small `laravel_knowledge` neighborhood to implementation prompts, `nativephp_knowledge` when the task names desktop or mobile, and `tarpit_knowledge` when it names those notes. That context is advisory and cannot change allowed files, the protected test, or completion.

Read [Laravel knowledge](knowledge-graph.md) for current scope and limits.

## Optional Jev classification through Laravel AI

Jev classification is optional and off by default (`MOLLY_JEV_ENABLED=false`). When disabled, Molly never opens a classification request and never upgrades a Pest, Tarpit, retry, or completion decision.

When you explicitly enable Jev, Molly borrows Laravel AI's classification seam (not a Molly-owned TypeSafe HTTP client) for selected, bounded evidence:

- plan suggestions
- failed-task advice
- PHP commit review

Inputs stay bounded to the evidence Molly already collected for that call. Provider credentials and transport belong to Laravel AI (`ai.providers.typesafe` / `TYPESAFE_API_KEY`), not a second Molly transport stack. Molly records the real provider provenance returned by Laravel AI.

Jev cannot replace Pest or Tarpit, cannot widen retries, and cannot make a failed run pass. Agents must not invent flags, env overrides, or MCP tools that bypass `MOLLY_JEV_ENABLED` or those deterministic gates.

See [Configuration](reference/configuration.md#jev-and-typesafe) for the settings.

## Review a PHP commit

```bash
php artisan molly:review-commit HEAD
php artisan molly:review-commit --staged --json
```

This reads a bounded PHP diff, runs Git's whitespace check, and optionally requests Jev classification through Laravel AI when that gate is enabled. It does not run tests, edit files, or create a commit.

## Current limits

Molly can save an Amp thread association and read Amp's reported executor connection state. That is not verified Orb identity and does not select a remote execution target.

Remote Orb identity and remote execution are still planned. See [Execution targets](execution-targets.md) only if you are working on that future feature.

## Next

- [Getting started](getting-started.md)
- [Task connections](connections.md)
- [Command reference](reference/commands.md)

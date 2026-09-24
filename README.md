# Molly

[![Package tests](https://github.com/sifrious/molly/actions/workflows/tests.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/tests.yml)
[![HOL Guard](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml)

**Yes, with receipts.** Molly is a local-first Laravel coding agent. The model can propose the work. Evidence decides whether the work is done.

Give Molly one small task, the files it may edit, and a Pest test that must pass. It asks an AI agent for the change, then runs real verification before the task can complete.

## Choose a path

| Path | What it is | Start here |
| --- | --- | --- |
| **Use Molly standalone** | Terminal-first Laravel workflow. No Bloom required. | [QuickStart: standalone](docs/quickstart-standalone.md) |
| **Use Molly with Bloom** | Bloom owns the workspace and review UI. Molly owns verification and evidence. | [QuickStart: Molly + Bloom](docs/quickstart-bloom.md) |

Bloom is optional. Everything below works without it.

## Install

You need PHP 8.3 or later, Laravel 12 or 13, Pest in the host app, Git, and a working database connection. See [Compatibility](docs/compatibility.md) for tested Pest versions.

Molly is not listed on Packagist yet, so Composer installs the tagged release from the public GitHub repository:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Add Molly's local files to `.gitignore`:

```gitignore
.molly/
```

## QuickStart: local Ollama

No paid AI account needed:

```bash
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

Then inspect the result:

```bash
php artisan molly:task demo-greeting
php artisan molly:show RUN_ID --verbose
git diff
vendor/bin/pest
```

`qwen2.5-coder:7b` is a starter suggestion that needs roughly 8 GB of free memory. Any local Ollama model works. Molly will not silently fall back to a hosted provider if Ollama is missing.

Prefer Amp? Run `php artisan molly:setup --agent=amp`, then `amp mcp approve molly` and `amp mcp doctor molly`.

`molly:doctor` also reports whether this host can sandbox the writer and verifier. macOS usually cannot. Read [Troubleshooting](docs/troubleshooting.md#sandbox-unavailable) if doctor reports `sandbox_unavailable`.

Full walkthrough: [QuickStart: standalone](docs/quickstart-standalone.md). Ollama details: [docs/ollama-quickstart.md](docs/ollama-quickstart.md).

### No Laravel app yet?

The demo installer creates a fresh Laravel app, installs Pest and the tagged Molly release, and scaffolds the greeting demo:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.3/bin/molly-demo -o molly-demo
bash molly-demo ~/molly-demo
```

It writes only under the chosen directory, refuses a non-empty path unless you pass `--force`, and does not install Bloom or call a hosted AI service. Continue from the `molly:setup` step above.

## What happens during a task

Molly keeps the order simple:

1. You define the task, writable files, and required Pest test.
2. The agent proposes changes only for those writable files. The required test stays protected by default.
3. Molly applies the proposal.
4. Pest runs against the required test file.
5. Tarpit reviews the changed files for unnecessary complexity.
6. Molly records separate Clever measurements before and after the change.
7. The task completes only when the required evidence passes.

A model saying its own work is correct is never enough. Failed Pest evidence cannot be overridden by a passing model review.

Molly does not commit your changes. Review the diff and run your broader test suite before committing.

## Why a task retries

Pest and Tarpit answer different questions. Pest checks the behavior named by the task. Tarpit checks the changed files for unnecessary complexity. Both are completion gates.

If either gate fails, Molly keeps the task incomplete and records the evidence for the next attempt. A passing complexity review cannot override a failed test, and a passing test does not hide unresolved complexity findings.

## Common commands

```bash
php artisan molly:create                 # Save a task
php artisan molly:start TASK             # Start a pending task
php artisan molly:task TASK              # Read task history
php artisan molly:show RUN_ID --verbose  # Read one run
php artisan molly:retry TASK             # Retry a failed or stopped task
php artisan molly:stop TASK              # Request a stop
php artisan molly:approve TASK --approve # Record human approval after required checks
php artisan molly:pr-opened TASK --url URL --approve # Record a human-opened pull request
php artisan molly:merged TASK --sha SHA --approve # Record a human merge commit
php artisan molly:doctor                 # Check setup
```

See the [command reference](docs/reference/commands.md) for the full list.

## Laravel knowledge graph

Molly can build a small local graph from its bundled Laravel queue, routing, testing, validation, container, eloquent, and events guidance and the Laravel source installed in your project. NativePHP Desktop v2 and Mobile v4 live in a separate namespace:

```bash
php artisan molly:knowledge:index laravel
php artisan molly:knowledge:query Queue
php artisan molly:knowledge:query Route
php artisan molly:knowledge:query Pest
php artisan molly:knowledge:query Validation
php artisan molly:knowledge:query Container
php artisan molly:knowledge:query Eloquent
php artisan molly:knowledge:query Events
php artisan molly:knowledge:index nativephp
php artisan molly:knowledge:query Desktop --namespace=nativephp --nativephp-version=desktop-2
php artisan molly:knowledge:query Mobile --namespace=nativephp --nativephp-version=mobile-4
php artisan molly:knowledge:index tarpit
php artisan molly:knowledge:query Tarpit --namespace=tarpit
```

The graph lives in `.molly/knowledge.sqlite`. It is local, disposable, and version-aware. Laravel knowledge covers queues, routing, testing, validation, the container, Eloquent, and events. NativePHP Desktop v2 and Mobile v4 stay separate. Tarpit notes stay separate and are not a quality score.

Read [Laravel knowledge](docs/knowledge-graph.md) for query examples and limits.

Molly can also rebuild a project graph from saved tasks, tests, attempts, and verification blockers:

```bash
php artisan molly:project:index
php artisan molly:project:query TASK_UUID
```

Read [Project graph](docs/project-graph.md) for node types and limits. The local web interface can show the same snapshot when it is enabled.

Record a Git-tracked decision with `php artisan molly:decide`. Molly writes `docs/decisions/` and does not commit the file.

## Local web interface

Molly also has a local browser interface for saved tasks and run evidence. It is disabled by default and has no login.

Read [Local web interface](docs/web-interface.md) before enabling it.

## Documentation

New to Molly:

- [Getting started](docs/getting-started.md)
- [QuickStart: standalone](docs/quickstart-standalone.md)
- [QuickStart: Molly + Bloom](docs/quickstart-bloom.md)
- [Compatibility](docs/compatibility.md)
- [Manage tasks](docs/tasks.md)
- [Understand verification](docs/verification.md)
- [Troubleshooting](docs/troubleshooting.md)

Using more features:

- [Agents and MCP](docs/agents.md)
- [Planning](docs/planning.md)
- [Laravel knowledge](docs/knowledge-graph.md)
- [Project graph](docs/project-graph.md)
- [Local web interface](docs/web-interface.md)
- [Task advice](docs/task-advice.md)
- [Task connections](docs/connections.md)
- [Journals](docs/journal.md)
- [Component snapshots](docs/component-snapshots.md)

Reference:

- [Commands](docs/reference/commands.md)
- [Configuration](docs/reference/configuration.md)
- [Glossary](docs/reference/glossary.md)
- [Documentation overview](docs/overview.md)

## What ships today and what does not

Current `v0.1.x` includes bounded Laravel coding tasks, protected acceptance tests, Pest verification with false-green protection, required Tarpit review, separate Clever measurements, bounded retries, persisted attempt history, local Ollama, explicit Amp integration, local MCP tools, a local web UI, the project graph, the Laravel knowledge graph, NativePHP knowledge namespaces, Git-tracked decisions, journals, source snapshots, and optional local component previews.

These are not finished yet:

- Verified Orb identity and remote execution selection
- Molly opening or merging pull requests itself. Molly records approvals, pull requests, and merges that people make.
- A compiled Bloom macOS integration. The Bloom adapter is unpublished.
- Visual Bloom preview display
- Laravel knowledge beyond queues, routing, testing, validation, the container, Eloquent, events, NativePHP, and Tarpit notes

Planned behavior is kept separate in [Execution targets](docs/execution-targets.md).

## Why "Molly"

Molly is named for Molly Bloom in James Joyce's *Ulysses*, whose chapter begins and ends with *yes*. Molly's yes has to be earned by evidence. Mary Perry's family has also always called her Molly. The thinking behind Tarpit and Clever comes from her Laracon US 2026 talk, [Cleverness Is A Loan](https://www.youtube.com/watch?v=vsxoaTgtyjw). More at [clever.mary.win](https://clever.mary.win/).

## Safety

Use Molly in a trusted development checkout. The required Pest test is protected by default. Writer and Pest processes run in a Landlock sandbox with a network namespace when the host supports it. `molly:doctor` reports whether that safe workflow is available. The `molly.sandbox.allow_unsafe` override is for local diagnostics in a trusted checkout only.

Review generated tests as carefully as generated application code. The weaker `--allow-test-edits` path is not the default.

Molly's GitHub workflows run the HOL Guard scanner with read-only repository access. See [SECURITY.md](SECURITY.md) for supported versions and private reporting guidance.

Molly is available under the [MIT license](LICENSE).

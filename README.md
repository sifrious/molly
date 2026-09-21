# Molly

[![Package tests](https://github.com/sifrious/molly/actions/workflows/tests.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/tests.yml)
[![HOL Guard](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml/badge.svg)](https://github.com/sifrious/molly/actions/workflows/plugin-security.yml)

Molly is a development tool for Laravel. Give it one small coding task, tell it which files it may edit, and give it a Pest test that must pass.

Molly asks an AI agent for the code change, then checks the result before it can complete the task.

> Molly v0.1.x is tagged. Prefer `composer require sifrious/molly:^0.1.1` (Packagist when listed; otherwise public GitHub VCS).

## QuickStart: Ollama

Recommended local path — **no paid AI account**. v0.1: [docs/v0.1/QUICKSTART.md · [Release gates](docs/v0.1/RELEASE-GATES.md)](docs/v0.1/QUICKSTART.md) · [FRICTION](docs/v0.1/FRICTION.md) · [WALKTHROUGH](docs/v0.1/WALKTHROUGH.md) · full guide: [docs/ollama-quickstart.md](docs/ollama-quickstart.md).

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
php artisan molly:run "Add a health endpoint" --file=routes/web.php --test=tests/Feature/ExampleTest.php
```

Or scaffold the greeting demo: `php artisan molly:demo` then `php artisan molly:start demo-greeting`.

`qwen2.5-coder:7b` is a starter suggestion (roughly ~8 GB RAM). Any installed local Ollama model works via `MOLLY_LOCAL_MODEL`. Molly will not silently fall back to a hosted provider if Ollama is missing.

## Start here

### No Laravel app yet?

One copy-paste path creates a fresh app, installs Molly, and scaffolds the greeting demo:

```bash
# Prefer a release-tagged script (no pipe-to-main):
# curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.1/bin/molly-demo -o molly-demo
# shasum -a 256 molly-demo  # compare to release notes checksum when published
# chmod +x molly-demo && ./molly-demo
```

Or download the script and choose a folder:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.1/bin/molly-demo -o molly-demo
bash molly-demo ~/molly-demo
```

That writes only under the chosen directory (default `~/molly-demo`). It refuses a path that already exists and is not empty unless you pass `--force`. It does not install Bloom or call cloud.

Then open that folder in Bloom with **Open existing branch…**, run `php artisan molly:doctor`, and `php artisan molly:start demo-greeting`.

### Already have a Laravel project?

You need PHP 8.3 or later, Laravel 12 or 13, Pest 4, and a working database connection.

Install Molly in a Laravel project:

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

Choose an agent.

For local Ollama:

```bash
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
```

For Amp:

```bash
php artisan molly:setup --agent=amp
amp mcp approve molly
amp mcp doctor molly
```

Then check the setup:

```bash
php artisan config:clear
php artisan molly:doctor
```

When Molly prints `Molly is ready.`, create your first task:

```bash
php artisan molly:demo                   # Optional first greeting task
php artisan molly:create
php artisan molly:start TASK_NAME
```

Read [Getting started](docs/getting-started.md) for a complete first task.

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
- [Documentation index](docs/index.md)

## What is current and what is planned

Current `v0.1.x` includes saved tasks, bounded retries, Amp and Ollama, Pest and Tarpit checks, Clever measurements, planning, local MCP tools, a local web UI with a project graph page, journals, Git-tracked decisions, source snapshots, optional local previews, Laravel knowledge for queues, routing, testing, validation, the container, Eloquent, and events, NativePHP Desktop v2 and Mobile v4 knowledge, and bundled tarpit notes. Pest fails a passing JUnit report that does not name the required test.

These are not finished yet:

- Verified Orb identity and remote execution selection
- Bloom still asks its agent to open and merge GitHub pull requests after Molly records approval
- A compiled Bloom macOS app. This Linux orb cannot run `swift test`
- Visual Bloom preview display
- Broader Laravel knowledge beyond queues, routing, testing, validation, the container, Eloquent, events, NativePHP, and tarpit notes

Planned behavior is kept separate in [Execution targets](docs/execution-targets.md).

## Safety

Use Molly in a trusted development checkout. The required Pest test is protected by default. Writer and Pest processes run in a Landlock sandbox with a network namespace when `molly:doctor` reports that isolation is available.

Review generated tests as carefully as generated application code. The weaker `--allow-test-edits` path is not the default.

Molly's GitHub workflows run the HOL Guard scanner with read-only repository access. See [SECURITY.md](SECURITY.md) for supported versions and private reporting guidance.

Molly is available under the [MIT license](LICENSE).

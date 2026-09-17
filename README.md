# Molly

Molly is a development tool for Laravel. Give it one small coding task, tell it which files it may edit, and give it a Pest test that must pass.

Molly asks an AI agent for the code change, then checks the result before it can complete the task.

> Molly is still in early development. There is no tagged alpha release yet. The install command below uses `dev-main`.

## Start here

You need PHP 8.3 or later, Laravel 13, Pest 4, and a working database connection.

Install Molly in a Laravel project:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:dev-main
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
php artisan molly:create
php artisan molly:start TASK_NAME
```

Read [Getting started](docs/getting-started.md) for a complete first task.

## What happens during a task

Molly keeps the order simple:

1. You define the task, editable files, and required Pest test.
2. The agent proposes changes only for those files.
3. Molly applies the proposal.
4. Pest runs against the required test file.
5. Tarpit reviews the changed files for unnecessary complexity.
6. Molly records separate Clever measurements before and after the change.
7. The task completes only when the required evidence passes.

A model saying its own work is correct is never enough. Failed Pest evidence cannot be overridden by a passing model review.

Molly does not commit your changes. Review the diff and run your broader test suite before committing.

## Common commands

```bash
php artisan molly:create                 # Save a task
php artisan molly:start TASK             # Start a pending task
php artisan molly:task TASK              # Read task history
php artisan molly:show RUN_ID --verbose  # Read one run
php artisan molly:retry TASK             # Retry a failed or stopped task
php artisan molly:stop TASK              # Request a stop
php artisan molly:doctor                 # Check setup
```

See the [command reference](docs/reference/commands.md) for the full list.

## Laravel knowledge graph

Molly can build a small local graph from its bundled Laravel queue guidance and the Laravel source installed in your project:

```bash
php artisan molly:knowledge:index laravel
php artisan molly:knowledge:query Queue
```

The graph lives in `.molly/knowledge.sqlite`. It is local, disposable, version-aware, and currently covers queues only.

Read [Laravel knowledge](docs/knowledge-graph.md) for query examples and limits.

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

Current `dev-main` includes saved tasks, bounded retries, Amp and Ollama, Pest and Tarpit checks, Clever measurements, planning, local MCP tools, a local web UI, journals, source snapshots, and the queue-focused Laravel knowledge graph.

These are not finished yet:

- Verified Orb identity and remote execution selection
- Bloom integration
- GitHub-to-Pest todo tracking
- Approval controls
- Visual component previews
- Complete lifecycle event history

Planned behavior is kept separate in [Execution targets](docs/execution-targets.md).

## Safety

Use Molly in a trusted development checkout. The file list limits what Molly may propose changing, but it is not a sandbox. Pest runs PHP with your local user's permissions.

Review generated tests as carefully as generated application code.

Molly is available under the [MIT license](LICENSE).

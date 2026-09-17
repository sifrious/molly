# Molly

Molly gives an AI agent one small Laravel task, limits which files it may
change, and requires a Pest test to pass before the task can complete.

Molly runs during development. Install it with Composer's `--dev` flag. It is
not part of your deployed application when production uses
`composer install --no-dev`.

> Molly is in early development. No alpha release is tagged yet. The setup below
> installs the current `main` branch.

## What Molly does

- Sends a bounded coding task to Amp or a local Ollama model.
- Limits edits to the test file and other files you select.
- Runs the required Pest test and all seven Tarpit checks.
- Records Clever measurements before and after the edit without combining them
  into a score.
- Saves each attempt, its evidence, and the reason a failed task did not
  complete.
- Shows the same task history in Artisan commands and a local web interface.

Molly does not retry a failed task on its own. You review the evidence and
decide whether to retry, stop, or edit the task.

## Quick start

Molly requires PHP 8.3 or later, Laravel 13, a working database connection, the
PHP DOM extension, and Pest 4.

Run these commands from the root of a Laravel application:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:dev-main
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Add Molly's local working directory to your application's `.gitignore`:

```gitignore
.molly/
```

### Use local Ollama

Start Ollama and download a coding model. This example uses Qwen2.5-Coder 7B:

```bash
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
```

Molly uses Ollama at `http://127.0.0.1:11434` by default. You can change the
address with `OLLAMA_URL`.

The public Ollama setup still needs a recorded live acceptance check. A model
appearing in `ollama list` confirms that it is installed, not that it can
complete a Molly task.

### Use Amp

Install the Amp CLI, then run:

```bash
php artisan molly:setup --agent=amp
amp mcp approve molly
amp mcp doctor molly
```

Amp owns its account credentials. Molly saves the selected agent in your
application's `.env` file.

### Check the setup

```bash
php artisan config:clear
php artisan molly:doctor
```

Continue when every check passes and the command prints:

```text
Molly is ready.
```

For Pest setup, provider details, and failed doctor checks, read the
[full installation guide](docs/getting-started.md).

## Run your first task

Create a saved task:

```bash
php artisan molly:create
```

Molly asks for:

1. The change you want.
2. An optional task name.
3. The Pest test that must pass.
4. Any other files the agent may change.

Creating the task does not call the model or edit files. Start it with the name
or UUID that Molly prints:

```bash
php artisan molly:start health-check
```

During the attempt, Molly:

1. Records the task and its allowed files.
2. Measures the selected code.
3. Requests and applies the proposed edits.
4. Runs Pest and Tarpit in parallel by default.
5. Saves the test output, Tarpit findings, measurements, and changed-file
   evidence.

Inspect the result and your working tree before committing:

```bash
php artisan molly:task health-check
php artisan molly:show RUN_ID --verbose
git status --short
vendor/bin/pest
```

If an attempt fails, fix any setup problem, review the changed files, and retry
it yourself:

```bash
php artisan molly:retry health-check
```

Molly keeps the earlier attempt. The default limit is three attempts.

## Local web interface

Molly also has a local task interface built with free Flux and Livewire. It has
no login and only accepts local requests.

Set these values in the host application's `.env` file:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
QUEUE_CONNECTION=database
```

Start a queue worker and local server, then open `http://127.0.0.1:8000/molly`:

```bash
php artisan queue:work --tries=1 --timeout=3600
php artisan serve --host=127.0.0.1 --port=8000
```

Before using queued tasks, read the
[web interface guide](docs/web-interface.md). Your queue's retry or visibility
timeout must exceed Molly's 3,600-second job timeout.

## Planning and optional TypeSafe evaluation

Molly can turn a larger request into a saved plan with citations to Laravel,
Tarpit, and NativePHP guidance:

```bash
php artisan molly:plan
```

Amp can also use Molly's local MCP tools to plan and manage tasks. TypeSafe
evaluation is optional and can help review plans, failed-task advice, and PHP
commits. Local tasks do not require a TypeSafe key.

Read [planning](docs/planning.md) and [agents and MCP](docs/agents.md) before
enabling these paths.

## Ask Molly about Laravel queues

Molly can build a small local graph from its pinned Laravel queue guide and the
framework source installed in your project:

```bash
php artisan molly:knowledge:index laravel
php artisan molly:knowledge:query Queue
```

The query returns nearby concepts such as jobs, retries, middleware, and queue
testing. Every node and relationship includes the documentation or source file
that supports it. Results are limited by depth and size, so Molly does not put
an entire manual into an agent request.

This first slice covers queues only. The graph lives in
`.molly/knowledge.sqlite`, stays local, and can be deleted and rebuilt. Read
[local Laravel knowledge](docs/knowledge-graph.md) for query options, version
rules, and the current scope.

## Documentation

- [Getting started](docs/getting-started.md) installs Molly and walks through
  one task.
- [Task management](docs/tasks.md) covers saving, inspecting, retrying,
  stopping, and importing tasks.
- [Verification](docs/verification.md) explains the Pest, Tarpit, and Clever
  evidence.
- [Agents and MCP](docs/agents.md) covers Amp, Ollama, MCP, and optional
  TypeSafe evaluation.
- [Local Laravel knowledge](docs/knowledge-graph.md) covers the versioned queue
  graph, provenance, and bounded queries.
- [Local web interface](docs/web-interface.md) explains the browser setup.
- [Troubleshooting](docs/troubleshooting.md) covers failed setup checks and
  task runs.
- [Command reference](docs/reference/commands.md) and
  [configuration](docs/reference/configuration.md) list commands and settings.
- [Glossary](docs/reference/glossary.md) defines Molly's terms.

The [documentation overview](docs/index.md) links to task connections, local
journals, component snapshots, contributing instructions, and the rest of the
guides.

## Current limits

The current `dev-main` build supports saved tasks, bounded retries, Amp and
Ollama, parallel Pest and Tarpit checks, a local web interface, planning, MCP
tools, local Laravel queue knowledge, optional TypeSafe evaluation, journals,
and component hashes.

The following work is not finished:

- Bloom integration.
- Verified Orb identity and remote execution-target selection.
- Tracked GitHub-to-Pest tasks.
- Approval controls.
- Visual component previews.
- Complete structured lifecycle event history.

Read the [execution target plan](docs/execution-targets.md) for the current
task-to-Amp-thread behavior and its limits.

## Safety

Use Molly in a trusted, disposable checkout. The allowed-file list limits
proposed edits, but it is not a sandbox. Pest runs PHP with your local user's
permissions, and the agent may edit the selected test file.

Review the diff and test assertions before accepting a result. A skipped check
never counts as a passing check.

## Source credit

Molly's bundled measurements derive from Clever commit
[`650a32a`](https://github.com/sifrious/cleverness/commit/650a32a595036ef610a7c7af1fad4869b23ef05a).
The original MIT notice and source reference remain in
[src/Complexity/LICENSE.md](src/Complexity/LICENSE.md). Molly does not require a
separate Clever installation.

Molly is available under the [MIT license](LICENSE).

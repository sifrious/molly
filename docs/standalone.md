---
layout: default
title: Molly on its own
---

# Molly on its own

Molly is a Laravel package. Everything it does is available from Artisan and its local web interface, and none of it needs Bloom. This page tours what you have after [Getting started](getting-started.md).

## Tasks from the terminal

The everyday loop is four commands:

```bash
php artisan molly:create
php artisan molly:start TASK
php artisan molly:show RUN_ID --verbose
php artisan molly:retry TASK
```

`TASK` is a nickname such as `ready-check` or the task's UUID. `molly:tasks` lists saved tasks, `molly:task TASK` shows one with its attempts, and `molly:stop TASK` stops a pending task or asks a running one to stop at its next step. [Tasks](tasks.md) covers each command.

Creating a task never calls the model. Starting one runs the model, the test, and the review in your terminal and exits when they finish. No queue worker is needed.

## Verification

A run completes only when the required Pest test passes, the Tarpit review finds no blocking problem, and both produced valid evidence. The model does not get a vote on its own work. [Verification](verification.md) walks through the gates and the receipts Molly writes under `.molly/receipts/`.

## The local web interface

Add to `.env`:

```dotenv
MOLLY_UI_ENABLED=true
```

Then clear the configuration cache and serve the application:

```bash
php artisan config:clear
php artisan serve
```

Open `http://127.0.0.1:8000/molly`. The pages are the task list, a task page with its attempts and an advice button, the run evidence page, plans, the project graph, and a create form. The web interface accepts loopback connections in the `local` and `testing` environments only, and it has no login. Starting a task from the browser needs a queue worker; [Web interface](web-interface.md) explains the queue requirements.

## Plans

When a request is too big for one task, save a plan and answer five short questions about the outcome, the data, what Laravel already gives you, the boundaries, and how you will verify it:

```bash
php artisan molly:plan 'Let people retry failed tasks from one page.'
```

A plan stores decisions. It does not write code. [Planning](planning.md) shows how a plan becomes tasks.

## Project graph

Molly can rebuild a graph of saved tasks, tests, runs, files, and blockers for a workspace, then answer questions like "why is this task blocked?" from the terminal:

```bash
php artisan molly:project:index
php artisan molly:project:query ready-check
```

The same graph is on the `/molly/graph` page. [Project graph](project-graph.md) lists the questions it answers.

## Laravel knowledge

Molly ships small excerpts of the Laravel documentation and indexes them with the framework source installed in your application, so an agent can be given a few relevant pages instead of a manual:

```bash
php artisan molly:knowledge:index laravel
php artisan molly:knowledge:query Queue
```

[Laravel knowledge](knowledge-graph.md) covers the namespaces and limits.

## Journals and decisions

`molly:journal TASK` exports a task's evidence to Markdown under `.molly/journal/`. `molly:decide` writes a short decision record under `docs/decisions/`, which you may commit. [Journals](journal.md) has both.

## Settings

Project settings live in `config/molly.php`. Global defaults shared across projects live in `~/.molly/settings.json` and are read and written by `molly:settings` and `molly:settings-set`. Every run saves the configuration it used, so changing settings later does not rewrite history. See [Settings](settings.md) and the [configuration reference](reference/configuration.md).

## Optional pieces

- [Amp](agents.md#amp) as the agent instead of Ollama.
- [MCP tools](agents.md#mcp-tools), so an editor or chat client can create and read tasks.
- [Jev advice](task-advice.md#advice-from-jev) through Laravel AI, off by default.
- [GitHub issues](tutorials.md#from-a-github-issue-to-a-task) as task sources, with comments and pull request records that a person approves.
- [Bloom](bloom.md), a desktop workspace around the same records.

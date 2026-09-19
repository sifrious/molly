---
layout: default
title: Molly documentation
---

# Molly documentation

Molly helps you give an AI agent a small Laravel task, limit the files it may edit, and require real test evidence before the task can complete.

If this is your first time here, start with [Getting started](getting-started.md). Do not start with the reference pages.

## Pick what you want to do

| I want to... | Read this |
| --- | --- |
| Install Molly and run one small task | [Getting started](getting-started.md) |
| Create, start, retry, stop, or inspect tasks | [Manage tasks](tasks.md) |
| Understand why a run passed or failed | [Verification](verification.md) |
| Fix a setup or execution problem | [Troubleshooting](troubleshooting.md) |
| Choose Amp or Ollama, or use MCP | [Agents and MCP](agents.md) |
| Break a larger request into tasks | [Planning](planning.md) |
| Query Laravel or NativePHP knowledge | [Laravel knowledge](knowledge-graph.md) |
| Inspect task, test, and blocker relationships | [Project graph](project-graph.md) |
| Use Molly in a browser | [Local web interface](web-interface.md) |
| Ask what to do after a failed task | [Task advice](task-advice.md) |
| Link a task to an Amp thread | [Task connections](connections.md) |
| Export local Markdown journals | [Journals](journal.md) |
| Compare selected component source hashes | [Component snapshots](component-snapshots.md) |

## Reference

Use these when you already know what you are looking for:

- [Command reference](reference/commands.md)
- [Configuration reference](reference/configuration.md)
- [Glossary](reference/glossary.md)

## Maintainer docs

These pages are not normal first-run documentation:

- [Contributing](contributing.md) explains the package test and CI workflow.
- [Execution targets](execution-targets.md) describes planned remote execution work. It is not current user behavior.
- [Work packages](work-packages.md) tracks the alpha backlog without Linear.
- [Publishing](publishing.md) is the maintainer plan for the documentation site.

## How Molly decides a task is done

The important order is:

1. The agent proposes code.
2. Molly applies only allowed-file changes.
3. Pest produces test evidence.
4. Tarpit reviews the supplied change for complexity.
5. Molly records measurements separately.
6. Required failures keep the run from completing.

The model does not get to declare its own work correct. A passing review never turns failed tests into a pass.

## Current scope

These docs describe the current `dev-main` build. No alpha release is tagged yet.

The Laravel knowledge graph currently covers queues, routing, testing, validation, the container, Eloquent, and events. NativePHP Desktop v2 and Mobile v4 live in a separate namespace. Bundled tarpit notes live in a separate namespace and are not a quality score. The project graph covers saved Molly records in one workspace and has a local web page at `/molly/graph`. BloomCore now binds a Molly contract to the selected workspace and waits for approval before the pull request strip. Remote Orb identity remains unfinished. Visual previews are optional and advisory.

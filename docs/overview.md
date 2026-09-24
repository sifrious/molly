---
layout: default
title: Molly documentation
permalink: /overview.html
---

# Molly documentation

Molly helps you give an AI agent a small Laravel task, limit the files it may edit, and require real test evidence before the task can complete.

If this is your first time here, start with [Getting started](getting-started.md). It routes you to the [standalone QuickStart](quickstart-standalone.md), which needs no Bloom, or the optional [Molly + Bloom QuickStart](quickstart-bloom.md). Do not start with the reference pages.

## Pick what you want to do

| I want to... | Read this |
| --- | --- |
| Install Molly and run one small task | [QuickStart: Molly standalone](quickstart-standalone.md) |
| Use Molly inside a Bloom workspace | [QuickStart: Molly + Bloom](quickstart-bloom.md) |
| Run a local Ollama model (no paid AI) | [Local Ollama details](ollama-quickstart.md) |
| Check PHP, Laravel, and Pest versions | [Compatibility](compatibility.md) |
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
- [Publishing](publishing.md) is the maintainer plan for the documentation site and the v1 release switch.
- [Clone Bloom and finish the Molly loop](handoffs/COMBINE-BLOOM-AND-MOLLY.md) is the handoff for the unpublished Bloom adapter under [docs/handoffs/bloom-adapter](handoffs/bloom-adapter/README.md).

## Historical notes

These pages record earlier release work. They are kept for context and are not current install guidance:

- [Work packages](work-packages.md), the alpha backlog
- [v0.1 QuickStart](v0.1/QUICKSTART.md), [friction notes](v0.1/FRICTION.md), [walkthrough](v0.1/WALKTHROUGH.md), and [release gates](v0.1/RELEASE-GATES.md)
- Bloom handoffs for [plugin hosting](handoffs/bloom-plugin-host-mme-5297.md) and [project flows](handoffs/bloom-project-flows-mme-5298.md)

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

These docs describe the tagged v0.1.x line and the current `main` branch. Molly works without Bloom.

The Laravel knowledge graph currently covers queues, routing, testing, validation, the container, Eloquent, and events. NativePHP Desktop v2 and Mobile v4 live in a separate namespace. Bundled tarpit notes live in a separate namespace and are not a quality score. The project graph covers saved Molly records in one workspace and has a local web page at `/molly/graph`. An unpublished Bloom adapter can bind a Molly contract to the selected workspace and wait for approval before its pull request controls. It is not yet a compiled, shipped Bloom release. Molly records pull requests and merges that people make and does not open or merge them itself. Remote Orb identity and execution remain unfinished. Visual previews are optional and advisory.

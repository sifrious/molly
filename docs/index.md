---
layout: default
title: Molly documentation
---

# Molly documentation

Give Molly a small coding task and choose the files Molly may change. Molly asks a local Ollama model for edits, runs a required Pest test file, and reviews the changes for unnecessary complexity.

The result includes test evidence, all seven Tarpit checks, and separate Clever measurements before and after the edit. A failed required check keeps the run from completing.

## Start here

[Install Molly and run your first task](getting-started.md). The guide covers a Laravel 13 application, PHP 8.3 or later, Ollama setup, and the result you should expect.

Then choose the next guide for your work:

| You want to | Read |
| --- | --- |
| Save tasks, inspect attempts, retry a failure, or import an issue | [Manage tasks](tasks.md) |
| Create and follow tasks in a browser | [Use the local web interface](web-interface.md) |
| Understand a completed or failed run | [Read verification and complexity evidence](verification.md) |
| Diagnose a setup error or interrupted run | [Troubleshoot Molly](troubleshooting.md) |

## Look up a detail

- [Command reference](reference/commands.md)
- [Configuration and environment variables](reference/configuration.md)
- [Glossary](reference/glossary.md)
- [Contributing and recorded verification](contributing.md)
- [Proposed Orb connections and execution targets](execution-targets.md)
- [Documentation publishing plan](publishing.md)

## Current release scope

These pages describe the current `dev-main` build. No alpha release is tagged yet. The CLI and local web interface share application actions. Pest and Tarpit review can run in parallel after Molly applies the selected edits. Clever commands are included in Molly.

Bloom integration, execution-target selection, tracked GitHub-to-Pest todos, approval controls, component previews, project journals, and TypeSafe evaluation are not implemented. The original alpha checklist still includes those capabilities. Their tutorials will follow working implementations.

The [Orb plan](execution-targets.md) describes shared connection management and task lookup through the CLI, MCP, and local web interface. Orb discovery and those MCP tools are proposed, not available in the current build.

Use a trusted, disposable checkout. The file allowlist limits the writer's proposals. Pest runs PHP with your local user's permissions, and Molly may edit the selected test file. Review the changed files and assertions before accepting a result.

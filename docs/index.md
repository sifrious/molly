---
layout: default
title: Molly documentation
---

# Molly documentation

Give Molly a small coding task and choose the files Molly may change. Molly asks your Amp agent or local Ollama model for edits, runs a required Pest test file, and reviews the changes for unnecessary complexity.

The result includes test evidence, all seven Tarpit checks, and separate Clever measurements before and after the edit. A failed required check keeps the run from completing.

## Start here

[Install Molly and run your first task](getting-started.md). The guide covers a Laravel 13 application, PHP 8.3 or later, agent setup, and the result you should expect.

Then choose the next guide for your work:

| You want to | Read |
| --- | --- |
| Save tasks, inspect attempts, retry a failure, or import an issue | [Manage tasks](tasks.md) |
| Review a project before turning the plan into tasks | [Plan with cited guidance](planning.md) |
| Connect Amp or choose a local model | [Agents and MCP](agents.md) |
| Decide what to do after a failure | [Get next-step advice](task-advice.md) |
| Find a task's latest or earlier Amp thread | [Task connections](connections.md) |
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

The build includes guided planning, nicknames, Amp and Ollama execution, MCP tools, optional TypeSafe planning and commit review, and failed-task advice. [Component snapshots](component-snapshots.md) record selected source hashes. [Local journals](journal.md) export saved task and attempt evidence to Markdown.

Bloom integration, remote execution-target selection, tracked GitHub-to-Pest todos, approval controls, visual component previews, and complete structured lifecycle event history remain unfinished. The original alpha checklist includes those capabilities.

[Task connections](connections.md) record exact task-to-Amp-thread associations and read current executor status through CLI, MCP, and web. Those observations do not establish a verified Orb identity. The [Orb plan](execution-targets.md) describes the remaining connection-management work.

Use a trusted, disposable checkout. The file allowlist limits the writer's proposals. Pest runs PHP with your local user's permissions, and Molly may edit the selected test file. Review the changed files and assertions before accepting a result.

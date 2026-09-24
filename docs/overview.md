# Molly documentation

Molly makes a small change to a Laravel application and proves it with Pest before the task counts as done. If you are new, read [Getting started](getting-started.md); it takes you from install to a completed task and needs nothing else.

## Guides

| Page | Read it when |
| --- | --- |
| [Getting started](getting-started.md) | You are installing Molly and running your first task. |
| [Tasks](tasks.md) | You want to create, start, inspect, retry, stop, or approve tasks. |
| [Verification](verification.md) | You want to know what has to pass and how to read a failure. |
| [Tutorials](tutorials.md) | You want the test-first flow, GitHub issues, component evidence, parallel work, Laravel AI tuning, Jev, or the web interface. |
| [Molly on its own](standalone.md) | You want a tour of everything that works without Bloom. |
| [Molly with Bloom](bloom.md) | You use Bloom and want to know what it adds today. |
| [Ollama](ollama-quickstart.md) | You are choosing a local model or reading doctor's Ollama codes. |
| [Agents and MCP](agents.md) | You want Amp, MCP tools, or Jev. |
| [Web interface](web-interface.md) | You want to read and start tasks in a browser. |
| [Planning](planning.md) | A request is too big for one task. |
| [Task advice](task-advice.md) | You want to know what a task allows next. |
| [Project graph](project-graph.md) | You want to know why a task is blocked or what a run touched. |
| [Laravel knowledge](knowledge-graph.md) | You want to see what the agent is given about Laravel. |
| [Journals and decisions](journal.md) | You want Markdown views of tasks or a committed decision record. |
| [Inspecting tasks and runs](inspection.md) | You want to follow a task to its runs and conversations. |
| [Component snapshots](component-snapshots.md) | You want before-and-after evidence for views and components. |
| [Amp thread links](connections.md) | You want to tie a task to an Amp conversation. |
| [Settings](settings.md) | You want global defaults across projects. |
| [Compatibility](compatibility.md) | You want the tested PHP, Laravel, and Pest versions. |
| [Troubleshooting](troubleshooting.md) | Something failed and you want the code explained. |

## Reference

- [Commands](reference/commands.md)
- [Configuration](reference/configuration.md)
- [Glossary](reference/glossary.md)

## For maintainers

- [Contributing](contributing.md) covers the package tests and CI.
- [Execution targets](execution-targets.md) is a design note for remote execution, which is not shipped.
- [Publishing](publishing.md) covers the docs link check and the checklist for switching the install line to v1.
- [Work packages](work-packages.md) is the alpha backlog.
- The `v0.1/` pages and the Bloom handoffs under `handoffs/` record earlier release work and are not current guidance.

## How Molly decides

1. The agent proposes a change to the files you allowed.
2. Molly applies it, leaving the protected test alone.
3. Pest runs the required test.
4. Tarpit reviews the diff for needless complexity.
5. Clever measures the code before and after.
6. The task completes only when the required checks pass.

A model saying its work is correct is never evidence. A passing review never rescues a failing test.

These pages describe the current `main` branch. The install lines use the latest tag, and a few things on these pages shipped after v0.1.3 and arrive with the next tag: doctor's Jev check, the fresh attempt budget after `molly:lock-test`, the approval requirement on `molly:pr-body`, and the `<?php` check on written tests. Everything on these pages works without Bloom.

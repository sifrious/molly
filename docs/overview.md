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
| [Acceptance criteria to Pest todos](github-todos.md) | You want a GitHub issue's acceptance criteria as a Pest file that the model fills in. |
| [Customize a step](customize-steps.md) | You want Molly's change writer, reviewer, or story step to use your own Laravel AI agent class. |
| [Web interface](web-interface.md) | You want to read and start tasks in a browser. |
| [Planning](planning.md) | A request is too big for one task. |
| [Task advice](task-advice.md) | You want to know what a task allows next. |
| [Project graph](project-graph.md) | You want to know why a task is blocked or what a run touched. |
| [Laravel knowledge](knowledge-graph.md) | You want to see what the agent is given about Laravel. |
| [Journals and decisions](journal.md) | You want Markdown views of tasks or a committed decision record. |
| [Inspecting tasks and runs](inspection.md) | You want to follow a task to its runs and conversations. |
| [Component snapshots](component-snapshots.md) | You want before-and-after evidence for views and components. |
| [Execution targets](execution-targets.md) | You want to know where a run executes and why Orb requests are refused. |
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
- [Publishing](publishing.md) covers the docs link check and the checklist for switching the install line to v1.
- [Work packages](work-packages.md) is the alpha backlog.
- The `v0.1/` pages and the Bloom handoffs under `handoffs/` record earlier release work and are not current guidance.

## Ownership and support

Molly owns the task, the protected test, verification, the Tarpit review, the Clever measurements, and every evidence record. Nothing else can mark a Molly task complete. The table says what each other project does for Molly on `main` and whether Molly supports it.

| Project | What it does for Molly | Status |
| --- | --- | --- |
| Laravel AI (`laravel/ai`) | Sends the change writer, Tarpit reviewer, and story prompts to Ollama, and Jev questions to TypeSafe. [Customize a step](customize-steps.md) replaces those agent classes. | Supported. Required dependency |
| Ollama | Runs the default local model. | Supported. Default agent |
| Amp CLI | Runs the change writer and reviewer with tools turned off, as an alternative to Ollama. | Supported. Optional |
| TypeSafe (Jev) | Answers optional advisory questions through Laravel AI. Never changes a result. | Supported. Off by default |
| Pest | Runs the required test. A task completes only after Pest passes. | Supported. Required in the application |
| GitHub (`gh`) | Reads issues for `molly:import` and posts approved comments. Molly never opens or merges a pull request. | Supported. Optional |
| Flux (free) and Livewire | Render the optional web interface. | Supported. Bundled |
| Bloom | Owns the checkout, diff, and pull request screens when you use it. | Optional. The plugin is built against Bloom commit `1599f05f`; host-level acceptance is pending. See [Molly with Bloom](bloom.md) |
| Orbs | Remote execution. | Not shipped. Molly refuses Orb requests. Whether the alpha includes it is an open release-scope decision. See [Execution targets](execution-targets.md#the-orb-requirement-is-open) |
| Prism | None. Molly has no Prism dependency and no Prism code; model calls go through Laravel AI. | Not supported. The MME-5211 documentation outline lists optional Prism routing, but no code exists and no release includes it |
| Rudy and Super Native | None. No Rudy or Super Native client exists, and Molly has no code for one. A future client would use the interfaces Molly has today: Artisan commands with `--json`, the MCP server, and the local web routes. | Planned. The MME-5211 outline lists a future client. Not built, and no release includes it |

## How Molly decides

1. The agent proposes a change to the files you allowed.
2. Molly applies it, leaving the protected test alone.
3. Pest runs the required test.
4. Tarpit reviews the diff for needless complexity.
5. Clever measures the code before and after.
6. The task completes only when the required checks pass.

A model saying its work is correct is never evidence. A passing review never rescues a failing test.

These pages describe the current `main` branch. The install lines use `^0.2`, which resolves once `v0.2.0` is tagged; the latest tag is `v0.1.3`, and prelaunch candidates are named `0.2.0-RC<n>`. [Release status](getting-started.md#release-status) says what to install today. A few things on these pages shipped after v0.1.3 and arrive with the next tag: doctor's Jev check, the fresh attempt budget after `molly:lock-test`, the approval requirement on `molly:pr-body` and `molly:handoff`, the saved handoff envelope, the MCP refusal of human decisions, and the `<?php` check on written tests. Everything on these pages works without Bloom.

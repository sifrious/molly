# Molly

Give Molly a small coding task and choose the files Molly may change. Molly asks a local Ollama model for edits, runs a required Pest test file, and reviews the changes for unnecessary complexity.

Read test evidence, all seven Tarpit checks, and separate Clever measurements in the terminal or local web interface. Retry a failed task with the previous attempt's diagnostics. Clever commands ship with Molly.

Molly is a Laravel package in development. No alpha release is tagged yet.

## Get started

Molly requires Laravel 13 and PHP 8.3 or later. The first-run guide covers Pest, Ollama, the public Composer repository, and a bounded task you can inspect afterward.

[Install Molly and run your first task](docs/getting-started.md).

## Documentation

| You need | Read |
| --- | --- |
| A map of the current build and its limits | [Documentation overview](docs/index.md) |
| Saved tasks, retries, stopping, and GitHub import | [Manage tasks](docs/tasks.md) |
| Browser setup and task controls | [Local web interface](docs/web-interface.md) |
| Completion rules, Tarpit findings, and Clever measurements | [Verification and complexity evidence](docs/verification.md) |
| Help with setup errors or a failed run | [Troubleshooting](docs/troubleshooting.md) |
| CLI options and JSON results | [Command reference](docs/reference/commands.md) |
| Defaults and environment variables | [Configuration reference](docs/reference/configuration.md) |
| Domain terms | [Glossary](docs/reference/glossary.md) |
| Package tests and recorded live checks | [Contributing](docs/contributing.md) |

The docs are Markdown in this repository. [The publishing plan](docs/publishing.md) proposes GitHub Pages at `molly.mary.win`. That hostname is not a published documentation link yet.

## Current scope

You can run one-off prompts or save tasks with attempt history, bounded retries, and stop requests. Molly permits one writing run per workspace, then runs Pest and Tarpit review in parallel by default. The local web interface uses free Flux and Livewire, with complete HTML pages and native forms.

Use a trusted, disposable checkout. Pest runs PHP with your local user's permissions, and the model may edit the selected test file. Review the diff and assertions before accepting a result.

The original alpha checklist still includes Bloom integration, execution-target selection, tracked GitHub-to-Pest todos, approval controls, component previews, project journals, and TypeSafe evaluation. These capabilities are not implemented yet.

## Source credit

Molly's bundled measurements derive from Clever commit `650a32a595036ef610a7c7af1fad4869b23ef05a` in [sifrious/cleverness](https://github.com/sifrious/cleverness). The original MIT notice and source reference remain in [src/Complexity/LICENSE.md](src/Complexity/LICENSE.md). Molly does not require a separate Clever installation.

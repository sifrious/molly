---
layout: default
title: Journals
---

# Journals

Molly can export task evidence to local Markdown files under `.molly/`.

The database remains the source of truth. Journals are readable local views, not a replacement database.

## Export one task

```bash
php artisan molly:journal health-check
```

Molly writes:

```text
.molly/journal/TASK_UUID.md
```

Run the command again after another attempt to refresh the same file.

The export includes the task request, file scope, required test, attempt states, Pest counts, Tarpit checks and findings, separate Clever measurements, recorded pull request or merge SHA when present, and lifecycle events from `.molly/lifecycle.jsonl`.

Missing evidence is shown as missing. A skipped check stays skipped.

## Project journal and glossary

Molly also maintains:

```text
.molly/JOURNAL.md
.molly/GLOSSARY.md
```

The project journal lists saved tasks and their linked attempts for that workspace.

The glossary contains a marked Molly-managed section. You may add project-specific definitions outside that section.

The project journal lists current saved records. The per-task journal also lists recorded lifecycle events from `.molly/lifecycle.jsonl`. Editing either Markdown file does not change the database.

## Refresh after a warning

If a task reports that the project journal could not be refreshed:

```bash
php artisan molly:journal health-check --project
```

For scripts:

```bash
php artisan molly:journal health-check --json --no-interaction
```

A journal write failure does not change the task's execution result.

## Privacy and Git

Journals can contain task descriptions and review explanations. Read them before sharing.

Molly writes them with restrictive local permissions and adds an ignore rule inside `.molly/.gitignore`. It does not change your repository's root `.gitignore`.

Already tracked files stay tracked, so keep `.molly/` ignored in your project.

## Architectural decisions

Molly can write a short decision record under `docs/decisions/` in the named workspace:

```bash
php artisan molly:decide --title="Keep Pest required" --body="Pest remains the hard completion gate."
```

That file is project memory. Commit it if the repository should keep it. Molly does not commit it for you. It is not a journal, and it does not start an agent.

## Next

- [Manage tasks](tasks.md)
- [Verification](verification.md)

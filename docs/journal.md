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

The export includes the task request, file scope, required test, attempt states, Pest counts, Tarpit checks and findings, and separate Clever measurements.

Missing evidence is shown as missing. A skipped check stays skipped.

## Project journal and glossary

Molly also maintains:

```text
.molly/JOURNAL.md
.molly/GLOSSARY.md
```

The project journal lists saved tasks and their linked attempts for that workspace.

The glossary contains a marked Molly-managed section. You may add project-specific definitions outside that section.

These files describe current saved records. They are not a complete event-by-event lifecycle log.

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

## Next

- [Manage tasks](tasks.md)
- [Verification](verification.md)

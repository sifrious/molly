---
layout: default
title: Task journal
---

# Task journal

Export a saved task and its attempts to a Markdown file in the task's workspace:

```bash
php artisan molly:journal health-check
```

Use the task's nickname or UUID. Molly writes `.molly/journal/TASK_UUID.md` and prints the full path. Export again after another attempt to replace the same file. Renaming a task updates its displayed nickname without changing the journal path or losing earlier attempts.

The journal includes the task request, editable files, required test, saved statuses and timestamps, failure reasons, Pest counts, all seven Tarpit checks, and findings with file paths and line numbers. Clever measurements appear separately. Missing evidence says `Not recorded`. Skipped checks remain skipped.

The host database remains the source of truth. Exporting does not run a model, execute tests, retry a task, or change its status. An active task may save more evidence after the export. Run the command again to refresh the file.

## Project chronology

Saving, importing, renaming, starting, or stopping a saved task refreshes `.molly/JOURNAL.md`. Each finished attempt refreshes the project journal again. The journal lists every saved task and linked attempt for that workspace in creation order. Entries include their stable UUIDs, current status, and saved update time. Refreshing replaces those entries without duplication.

The same operation writes Molly's task, attempt, verification, and complexity terms in a marked section of `.molly/GLOSSARY.md`. Add project-specific definitions outside that section. Molly preserves the surrounding text and refuses an update if the file changes during export. An incomplete or repeated section marker causes an export error so Molly does not replace ambiguous content.

These files are generated views of current records. They do not preserve every intermediate status transition. A complete structured lifecycle event log remains separate work. One-off `molly:run` executions are outside the saved-task project journal. The per-task CLI command above exports only the requested task.

## Retry a project journal warning

The task page and CLI report show the last journal result. A failed journal write does not change the saved task or run result. After correcting the failure, refresh the project files:

```bash
php artisan molly:journal health-check --project
```

Add `--json` to return `task_id`, `status`, `checked_at`, and the journal paths or failure reason. A written journal exits with code `0`; an unavailable journal exits with code `1`. Refreshing the journal does not change the task's lifecycle update time. Older tasks with no recorded refresh show that the journal has not been refreshed.

`src/Actions/RefreshProjectJournal.php` calls `ExportTaskJournal::forWorkspace($workspace)` and records the result in `molly_tasks.journal_status`. The task lifecycle actions share that behavior across CLI, web, and MCP.

## Use the journal in scripts

```bash
php artisan molly:journal health-check --json --no-interaction
```

On success, the command returns `path`, `task_id`, and `attempt_count` and exits with code `0`. The task ID is always the UUID. The path points to the completed Markdown file.

On failure, the command exits with code `1` and returns `task`, `status: "error"`, and `error`. `TASK_NOT_FOUND` means the name or UUID did not match a saved task. `JOURNAL_PATH_INVALID` means a journal directory or destination is unsafe, such as a symbolic link. `JOURNAL_WRITE_FAILED` means Molly could not create or replace the file. A failed export leaves saved task and attempt records unchanged.

## Keep the export local

Molly exports selected evidence. It does not copy source snapshots, full test output, environment files, or provider configuration into the journal. The task request and recorded explanations may still contain private project information. Review the file before sharing it.

Molly writes through a temporary file in the journal directory, then replaces the destination. It refuses symbolic links, nonregular destinations, and files with multiple hard links. Directories Molly creates use mode `0700`, and exported files use mode `0600`.

Journal export adds a final `*` rule to `.molly/.gitignore` if needed. Existing rules remain in the file. Git then ignores the journal artifacts by default. Already tracked files remain tracked. Molly does not edit the workspace's root `.gitignore`.

The application action is `src/Actions/ExportTaskJournal.php`. The command in `src/Console/MollyJournalCommand.php` formats its result. See [tasks](tasks.md) for the saved lifecycle and [verification](verification.md) for the evidence each attempt records.

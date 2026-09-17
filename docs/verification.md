---
layout: default
title: Verification
---

# Verification

Use this page when you want to understand why a Molly run completed or failed.

The central rule is simple: deterministic evidence comes before model judgment. A model cannot grade its own work into a passing state.

## What must happen for a run to complete

After an agent proposes edits, Molly:

1. Validates that the proposal only touches allowed files.
2. Applies the changes.
3. Runs the required Pest test file.
4. Runs the seven-check Tarpit review.
5. Records Clever measurements before and after the change.
6. Confirms the selected files still match the reviewed contents.

A required failure blocks completion.

A passing Tarpit review cannot override failed Pest evidence. A model saying that tests should pass is not test evidence.

## Read a saved run

```bash
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
```

Reading a report does not execute the model again.

The verbose report is the best place to diagnose a failure because it includes test output, review findings, measurement details, and execution branch metadata.

## Pest verification

Molly runs only the required Pest test file for the task. It requires real JUnit evidence and at least one executed test.

These prevent completion:

- failed or errored tests
- skipped or incomplete tests
- risky tests or warnings that make the run unusable
- a timeout
- missing or invalid JUnit evidence
- an empty test file

Molly does not run your entire application test suite. Run the broader suite yourself before committing:

```bash
vendor/bin/pest
```

## Tarpit review

Tarpit receives the task plus the selected files before and after the change. It does not explore the whole repository or run tests.

The seven checks ask about:

| Check | Looks for |
| --- | --- |
| A | Stored values that could be derived instead |
| B | Business decisions mixed with I/O, time, randomness, or mutation |
| C | Application decisions living in transport or rendering code |
| D | Hidden ordering or setup requirements |
| E | Abstractions or code with no current requirement |
| F | Excessive size, nesting, or duplication |
| G | Caches or indexes that create hidden behavior or dependencies |

A finding is `essential`, `pragmatic`, or `accidental`. Only unresolved accidental complexity can be `blocking`.

The review is one source of evidence. It is not a replacement for Pest.

## Clever measurements

Molly records several code measurements before and after the edit. It never combines them into one score.

Available commands include:

```bash
php artisan clever:scan
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

The measurements answer different questions:

- `owned-diff` counts current code, comments, blank lines, and files in configured paths.
- `welds` finds literal constructor and static-call sites worth inspecting.
- `lonely-files` lists qualifying PHP files with one recorded Git author.
- `hotspots` shows files with both current size and recent commit activity.

A smaller number is not automatically better. Read each probe's scope, warnings, and skip reason.

## Parallel and serial checks

Pest and Tarpit run in parallel by default. Both must finish with valid evidence.

Parallel mode requires `posix_setsid` and `posix_kill`. If those functions are unavailable, set:

```php
'parallel_checks' => false,
```

in `config/molly.php`, then run:

```bash
php artisan config:clear
php artisan molly:doctor
```

Serial mode runs the same required checks one after another.

## Source snapshots

Molly records hashes for selected files when a task is created and around each run. This helps show which selected files changed.

A hash comparison does not prove a visible UI change. Molly does not currently render component previews.

Read [Component snapshots](component-snapshots.md) for the exact behavior.

## Safety and limits

The file allowlist restricts what the writer may propose changing. It is not a sandbox. Pest still runs PHP with your local user's permissions.

The required test file is writable by the model. Review its assertions before accepting the result.

Failed runs may leave applied edits in the workspace. Molly does not commit them.

## Next

- [Troubleshooting](troubleshooting.md)
- [Manage tasks](tasks.md)
- [Configuration](reference/configuration.md)

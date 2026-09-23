---
layout: default
title: Verification
---

# Verification

Use this page when you want to understand why a Molly run completed or failed.

The central rule is simple: deterministic evidence comes before model judgment. A model cannot grade its own work into a passing state.

## What must happen for a run to complete

After an agent proposes edits, Molly:

1. Validates that the proposal only touches allowed files and does not change a protected test.
2. Applies the changes.
3. Checks the protected test digest again.
4. Runs the required Pest test file.
5. Runs the seven-check Tarpit review.
6. Records Clever measurements before and after the change.
7. Confirms the selected files and protected test still match the reviewed contents.

A required failure blocks completion.

A passing Tarpit review cannot override failed Pest evidence. A model saying that tests should pass is not test evidence.

## Read a saved run

```bash
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
```

Reading a report does not execute the model again.

## Read verification receipts

Every completed or terminated run finalizes immutable JSON receipts under `.molly/receipts/<run-id>/`.

```bash
php artisan molly:receipt RUN_ID
php artisan molly:receipt RUN_ID --workspace=/path/to/repo
php artisan molly:receipt RUN_ID --json
```

Receipts record verifier name, state (`PASS` / `FAIL` / `REVIEW_REQUIRED` / `NOT_RUN`), policy, failure action, evidence digest, and timestamps. Reading them does not rerun Pest, Tarpit, or the model.

A run that stops or fails before the normal completion decision still gets receipts. Verifiers that never executed appear as `NOT_RUN` rather than being omitted. Finalized receipt files are append-only for that run id; a retry creates a new run id and a new receipt directory.


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
- a test file that runs but records zero assertions
- a Pest process that exits successfully while JUnit records failures
- JUnit evidence that does not name the required test file

A completed or failed run also writes immutable verification receipts under `.molly/receipts/{run-id}/`. Each receipt stores verifier name, state, policy, failure action, evidence digest, and timestamps. Receipts do not replace Pest. Rewriting a receipt file does not change the saved run.

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

A hash comparison does not prove a visible UI change. Optional local previews are advisory and never override Pest.

Read [Component snapshots](component-snapshots.md) for the exact behavior.

## Safety and limits

The required Pest test is protected by default. A proposal that changes it is rejected before application. Molly also fails the run if the test digest changes on disk.

Writer and Pest processes run in a Landlock sandbox with a network namespace when the host supports it. `molly:doctor` reports that as the Sandbox check. Pest still executes PHP, so keep the workspace disposable.

Set `molly.sandbox.allow_unsafe` only for local diagnostics. That override is conspicuous, local-only, and excluded from release evidence.

Failed runs may leave applied edits in the workspace. Molly does not commit them.

## Next

- [Troubleshooting](troubleshooting.md)
- [Manage tasks](tasks.md)
- [Configuration](reference/configuration.md)

## False-green detection (opt-in)

When `molly.false_green.enabled` is true, Molly runs a bounded negative-control probe after Pest:

1. Temporarily replace a selected production file with a throwing stub.
2. Re-run the required Pest file.
3. Restore the original file before the next mutation (canonical workspace must match).

If the test stays green, the `false_green` verifier fails and blocks completion. If the test fails under mutation, the probe passes. Timeouts and probe errors are `REVIEW_REQUIRED` / inconclusive — never treated as PASS. Budgets: `max_mutations` and `timeout_seconds`.


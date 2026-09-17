---
layout: default
title: Troubleshooting
---

# Troubleshooting

Start with saved evidence instead of retrying blindly.

Run these first:

```bash
php artisan molly:doctor --json
php artisan molly:task TASK --json
php artisan molly:show RUN_ID --verbose
```

Replace `TASK` and `RUN_ID` with the values from your run.

## Doctor fails

After changing `.env` or Molly configuration, always run:

```bash
php artisan config:clear
php artisan molly:doctor
```

Common doctor codes:

| Code | What to do |
| --- | --- |
| `migration_missing` | Review pending migrations, then run `php artisan migrate`. |
| `database_unavailable` | Fix the host application's database connection. |
| `pest_missing` | Install Pest 4 in the selected workspace. |
| `amp_unavailable` | Check `amp usage` and Amp login. |
| `parallel_process_groups_unavailable` | Install POSIX support or set `parallel_checks` to `false`. |
| `model_not_configured` | Set `MOLLY_LOCAL_MODEL` to a model from `ollama list`. |
| `model_not_local` | Choose a local model instead of a cloud model name. |
| `ollama_unreachable` | Start Ollama and check `OLLAMA_URL`. |
| `model_missing` | Pull the configured model or correct the model name. |
| `clever_disabled` | Use local/testing or remove an explicit false override. |
| `clever_unavailable` | Check the package install and application logs. |

Restart long-running queue workers after configuration changes.

## Composer cannot install Molly

Check the runtime Composer is using:

```bash
php --version
composer show laravel/framework
composer check-platform-reqs
```

Molly currently requires PHP 8.3 or later and Laravel 13. There is no tagged release yet, so the documented install uses `dev-main`.

## Pest fails

Read the recorded test output:

```bash
php artisan molly:show RUN_ID --verbose
```

Then run the required test directly:

```bash
vendor/bin/pest tests/Feature/YourTest.php
```

Common reasons:

| Reason | Meaning |
| --- | --- |
| `tests_failed` | An assertion or test execution failed. |
| `no_tests` | Molly could not find an executed test in the required file. |
| `tests_skipped_or_incomplete` | The required evidence is incomplete. |
| `test_timeout` | Pest exceeded the configured timeout. |
| `test_process_failed` | The Pest process exited unsuccessfully. |
| `junit_missing` / `junit_invalid` | Molly cannot trust the recorded test evidence. |

Do not weaken assertions just to make a retry pass.

## Tarpit blocks completion

Read the finding's file, line, problem, classification, and recommendation.

Only unresolved accidental complexity can be blocking. `REVIEW_INVALID` means the model returned an incomplete or inconsistent review, so Molly has no valid review evidence to accept.

A passing review cannot override failed tests.

## The model is slow or returns invalid changes

The default proposal/review timeout is 180 seconds.

Before raising it, try a smaller task. If you do change the timeout, edit `config/molly.php`, clear configuration, and run doctor again.

Useful failure names include:

- `GENERATION_INVALID`: provider output did not match the proposal contract.
- `CHANGES_INVALID`: the proposal was empty, duplicated a file, or named a disallowed file.
- `FILE_TOO_LARGE`: selected content or a replacement exceeded the configured limit.
- `NO_CHANGES`: the proposal did not change the selected files.

## A parallel check fails

Use:

```bash
php artisan molly:show RUN_ID --verbose
```

Look for the failing branch and classification.

Common values:

- `branch_start_failed`
- `branch_timeout`
- `branch_cancelled`
- `branch_result_invalid`
- `CHECK_PROCESS_GROUP_UNAVAILABLE`

Both Pest and review branches need valid results. One passing branch cannot stand in for the other.

## A task is stuck in `running`

Read it before doing anything else:

```bash
php artisan molly:task TASK
php artisan molly:stop TASK
php artisan molly:task TASK
```

Do not delete `.molly` lock files to force a retry. `WORKSPACE_BUSY` means Molly could not acquire a lock. Check whether work is still running first.

## Retry is rejected

`molly:start` is for pending tasks. `molly:retry` is for failed or stopped tasks.

`ATTEMPT_LIMIT_REACHED` means the task has used its configured number of attempts. The default is three total attempts.

For a saved explanation of the next allowed action:

```bash
php artisan molly:advice TASK
```

## The web UI returns 404 or no run appears

Check:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
```

Then:

```bash
php artisan config:clear
php artisan queue:failed
```

Make sure the queue worker is running and the queue reservation/visibility timeout is above 3600 seconds.

CLI execution with `molly:start` does not need a queue worker.

## Workspace changed during a run

`WORKSPACE_CHANGED` means a selected file no longer matches the saved content Molly expected at that stage.

Avoid editing selected files while Molly runs. Inspect the current Git diff and the saved hashes before retrying.

Failed verification does not automatically restore an applied proposal.

## Still stuck?

Use the references for exact settings and return fields:

- [Command reference](reference/commands.md)
- [Configuration](reference/configuration.md)
- [Verification](verification.md)

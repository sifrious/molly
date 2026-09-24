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
| `pest_missing` | Install Pest in the selected workspace. See [Compatibility](compatibility.md) for tested versions. |
| `sandbox_unavailable` | The host cannot isolate the writer and verifier. See [Sandbox unavailable](#sandbox-unavailable). |
| `sandbox_unsafe_override` | The local diagnostics override is on. Do not use it for untrusted code. |
| `amp_unavailable` | Check `amp usage` and Amp login. |
| `parallel_process_groups_unavailable` | Install POSIX support or set `parallel_checks` to `false`. |
| `model_not_configured` | Set `MOLLY_LOCAL_MODEL` to a model from `ollama list`. |
| `model_not_local` | Choose a local model instead of a cloud model name. |
| `ollama_unreachable` | Start Ollama (`ollama serve`) and check `OLLAMA_URL` / `ai.providers.ollama.url`. Unreachable is **not** the same as a missing model. |
| `model_missing` | Ollama is up; pull the configured model (`ollama pull …`) or fix `MOLLY_LOCAL_MODEL`. |
| `ollama_config_invalid` | Use loopback HTTP + Ollama driver + local model + positive `molly.timeout`. |
| `ollama_endpoint` | Informational: shows the configured base URL (no secrets). |
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

Molly currently requires PHP 8.3 or later and Laravel 12 or 13. Install a tagged release, never a development branch:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
```

Molly is not listed on Packagist yet, so Composer needs the repository line. Without it, Composer reports that it cannot find `sifrious/molly`.

## Sandbox unavailable

Molly runs the writer and the Pest verifier inside a Landlock sandbox with Linux user and network namespaces when the host supports them. `molly:doctor` reports the result as the Sandbox check.

| Signal | Meaning |
| --- | --- |
| Doctor `sandbox_unavailable` | The host has no Landlock or user and network namespaces. This is normal on macOS. |
| `SANDBOX_UNAVAILABLE` from `molly:start` | Molly refused the safe workflow on this host. |
| Doctor `sandbox_unsafe_override` | Someone set the local diagnostics override. |

Run the safe workflow on a Linux host that supports those features.

For diagnostics in a trusted checkout only, you can turn isolation off:

```dotenv
MOLLY_SANDBOX_ALLOW_UNSAFE=1
```

Then run `php artisan config:clear` and `php artisan molly:doctor`. Doctor reports `sandbox_unsafe_override`. Writer and Pest processes then run with your user's full permissions. Never use this for untrusted repositories or shared machines, and do not count runs made this way as release evidence.

If `molly:start` already failed on the sandbox, the task is `failed`. Use `php artisan molly:retry TASK` after fixing the host rather than editing files by hand.

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
| `no_assertions` | Pest ran a test file but recorded zero assertions. |
| `false_green` | JUnit recorded a pass that did not identify the required test file. |

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

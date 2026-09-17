---
layout: default
title: Troubleshooting
---

# Troubleshooting

Start with the saved evidence. A failed run can contain applied edits, passing checks, and one failed check. Read each result before retrying.

```bash
php artisan molly:doctor --json
php artisan molly:task TASK_ID --json
php artisan molly:show RUN_ID --verbose
```

Replace `TASK_ID` and `RUN_ID` with the IDs from Molly's output. `molly:show` reads the saved report without running the model again. A successful lookup exits zero even when the saved run failed. `molly:start` and `molly:retry` exit zero only when the new run completes.

## Doctor reports a failed check

`molly:doctor --json` includes a `code` for each check. Resolve failed checks, clear cached configuration, then run doctor again.

| Code | What to check |
| --- | --- |
| `migration_missing` | Run `php artisan migrate` in the host application after reviewing pending migrations. Molly needs both `molly_tasks` and `molly_runs`. |
| `database_unavailable` | Check the application's database connection and credentials. Molly uses the host application's database configuration. |
| `pest_missing` | Install Pest 4 in the selected workspace. `--workspace` must point to the project that contains `vendor/bin/pest`. |
| `parallel_process_groups_unavailable` | Enable PHP's `posix_setsid` and `posix_kill`, or set `parallel_checks` to `false` in `config/molly.php`. |
| `model_not_configured` | Set `MOLLY_LOCAL_MODEL` in `.env` to a model listed by `ollama list`. |
| `model_not_local` | Select a local model whose name does not contain `cloud`. |
| `ollama_config_invalid` | Use the `ollama` driver, a loopback HTTP URL, a local model name, and a positive integer `molly.timeout`. |
| `ollama_unreachable` | Start the Ollama application or server. Check `OLLAMA_URL` and run `ollama list`. |
| `ollama_response_invalid` | Confirm that the configured port serves Ollama. Doctor expects a successful `/api/tags` response containing a model list. |
| `model_missing` | Pull the configured model or correct `MOLLY_LOCAL_MODEL` to match the installed name. |
| `clever_disabled` | Use `APP_ENV=local` or `testing` and remove a false `MOLLY_COMPLEXITY_ENABLED` override. Measurements stay disabled in production. |
| `clever_unavailable` | Check the package installation and application logs. Clever ships inside Molly; installing another Clever package is unnecessary. |

After changing `.env` or published configuration:

```bash
php artisan config:clear
php artisan molly:doctor
```

Restart long-running queue workers after changing configuration. See the [configuration reference](reference/configuration.md) for keys and defaults.

## Composer cannot install Molly

Molly requires PHP 8.3 or later, the DOM extension, and Laravel 13 components. Check the PHP version used by the terminal and Composer:

```bash
php --version
composer show laravel/framework
composer check-platform-reqs
```

Use the public VCS repository and the explicit `dev-main` requirement from [getting started](getting-started.md#install-molly). No tagged Molly version is available yet. Read Composer's conflict output before changing existing application dependencies.

## Pest fails or reports no usable evidence

Read the Pest output in `molly:show RUN_ID --verbose`. Run the selected test directly from the workspace to reproduce the failure:

```bash
vendor/bin/pest tests/Feature/MollyHealthTest.php
```

Replace the example path with the task's required test. Molly adds strict failure flags and JUnit output to the recorded command. A direct run without those flags may finish differently when tests are skipped, risky, or incomplete.

| Verification reason | Next step |
| --- | --- |
| `tests_failed` | Read the assertion failure or exception. Repair the behavior or ask Molly to retry with the saved diagnostics. Do not weaken assertions to make the result pass. |
| `no_tests` | Check that the selected file contains an executable Pest test. A file containing only setup code cannot verify a task. |
| `tests_skipped_or_incomplete` | Implement required tests and remove skip or todo markers only when the promised behavior exists. |
| `test_timeout` | Check for a hanging test or slow dependency. Set a justified `test_timeout` in `config/molly.php`; Molly bounds the value to 1 through 3600 seconds. |
| `test_process_failed` | Read process output for PHP errors, missing extensions, risky tests, warnings, or a nonzero Pest exit. |
| `junit_missing` or `junit_invalid` | Inspect the recorded command, Pest output, and evidence path. Molly cannot accept missing or inconsistent test evidence. |
| `evidence_directory_unwritable` | Check that the application user can create and write the run's evidence directory under `storage/molly`. |

If a feature test cannot access the application or Laravel test helpers, check the `Tests\TestCase` binding in `tests/Pest.php`. The [getting started guide](getting-started.md#prepare-a-laravel-application) shows the binding.

## Tarpit review blocks completion

A valid review includes checks A through G and consistent findings for selected files. Read each finding's path, line, problem, and recommendation.

An accidental-complexity finding with `blocking` severity prevents completion. Warnings remain visible. `REVIEW_INVALID` means the model returned an incomplete or inconsistent review, so Molly has no valid review decision to accept.

For a saved task, inspect the changes and use `molly:retry TASK_ID` when another attempt is justified. Retry preserves the original task and file scope. A narrower task may work better when the model cannot keep the review and proposed edits within the selected files.

## A model call is slow or returns invalid changes

Confirm that the model is installed and the Ollama server is responding. Doctor does not exercise model generation, so a passing setup check does not prove the model will meet Molly's response schema.

The default model timeout is 180 seconds per request. Edit the positive integer `timeout` in `config/molly.php` if local inference needs longer, then clear cached configuration. Prefer a smaller task before increasing timeouts. Parallel review deadlines are bounded to 3600 seconds plus startup and cleanup time.

`GENERATION_INVALID` means the model's response failed the proposal schema or named an unselected file. `CHANGES_INVALID` means the workspace rejected an empty proposal, a repeated file, or a file outside the allowed list. `FILE_TOO_LARGE` means selected content or a proposed replacement exceeded the configured file limit. `NO_CHANGES` means the proposal left the selected files unchanged. Molly does not mark unchanged output as verified new work.

## A parallel check fails

Use `molly:show RUN_ID --verbose` to read branch IDs, timestamps, failure classifications, and result references.

| Classification or error | Meaning and next step |
| --- | --- |
| `branch_start_failed` | The host could not launch the child check. Read the recorded error and check the PHP executable, host `artisan`, and evidence directory. |
| `branch_timeout` | A check exceeded its deadline. Read the affected branch and its timeout configuration. |
| `branch_cancelled` | The task received a stop request. Inspect applied changes before retrying. |
| `branch_result_invalid` | The child result was missing or did not match the expected branch and attempt. Read the recorded error. A process exit alone is not passing evidence. |
| `CHECK_PROCESS_GROUP_UNAVAILABLE` | The check could not establish the POSIX process group needed for cleanup. Resolve the PHP environment or select serial checks explicitly. |

Both branches must pass and record results. A passing Pest branch cannot replace missing review evidence, and a passing review cannot replace failed tests.

## A task stays running after the process exits

Read the task and latest run first. A recorded `running` status may outlive the process that wrote the status.

```bash
php artisan molly:task TASK_ID
php artisan molly:stop TASK_ID
php artisan molly:task TASK_ID
```

Stop saves a request for an active run. Molly checks stop requests between stages and before applying generated changes. Generation must finish before Molly can honor the request. Parallel checks receive cancellation; serial checks finish the active test or review before stopping.

If the original process has exited, stop settles an interrupted task only after the task, workspace, and active-check locks are free. The attempt records `RUN_INTERRUPTED` and retains saved evidence. An active child check may still own a lock after its parent exits.

Do not delete `.molly` lock files to force a retry. `WORKSPACE_BUSY` means Molly could not acquire a required lock. Wait for active execution to stop or inspect the remaining process before running another attempt. `WORKSPACE_LOCK_INVALID` indicates an invalid lock path or a permissions problem, not proof that execution has stopped.

## Retry is rejected

`molly:start` accepts pending tasks. `molly:retry` accepts failed or stopped tasks. A completed task cannot be retried.

`ATTEMPT_LIMIT_REACHED` means the task used the configured total number of attempts. The default is three, including the first run. Inspect previous evidence before changing the task's scope or configuration. `molly.max_attempts` accepts integers from 1 through 10. Molly never retries automatically.

## Workspace contents changed during execution

`WORKSPACE_CHANGED` can occur before Molly applies the proposal or after verification. Read the complete message to identify the stage. Another editor, process, or test may have changed a selected file.

Review the current files against the saved hashes and your Git diff. Avoid editing selected files while Molly runs. Failed verification does not roll back an applied proposal.

`WORKSPACE_WRITE_FAILED` means Molly could not finish applying the proposal and restored the original selected contents. `WORKSPACE_ROLLBACK_FAILED` lists files Molly could not restore. Inspect those files before continuing.

## Clever is skipped or unavailable

Read each probe's status and caveats in the run report. Git-dependent probes can be skipped when the checkout has no commits. A skipped probe does not count as a passing measurement, but the skipped probe alone does not block a run.

`CLEVER_UNAVAILABLE` stops the run when measurements are disabled or a scan fails. Inspect the report reason and details. Standalone commands use the host application's root or `molly-complexity.root`; select the same project when comparing standalone measurements with a run.

See [verification and complexity review](verification.md) for the commands and measurement limits.

## The web interface returns 404 or rejects access

Check `MOLLY_UI_ENABLED=true`, clear cached configuration, and use the configured prefix, which defaults to `/molly`.

The interface requires `APP_ENV=local` or `testing`, a direct loopback connection, and a `localhost`, `127.0.0.1`, or `[::1]` host. A custom development domain, remote client, or production environment fails the local access guard. The same guard applies to Livewire status requests.

The interface has no login or approval controls. Open the local server directly rather than through a reverse proxy or tunnel. Follow the [web interface setup](web-interface.md).

## Start was requested, but no attempt appears

Web start and retry submit a queue job. The confirmation means the request was queued, not that a run started. Check the worker output and failed jobs:

```bash
php artisan queue:failed
```

The queue connection must use database, Redis, Beanstalkd, or SQS. For database, Redis, and Beanstalkd, set the connection's `retry_after` in `config/queue.php` above 3600 seconds. The [web interface guide](web-interface.md) uses 3700 seconds. SQS needs a visibility timeout above 3600 seconds in AWS.

Database queues also need the host application's jobs and failed-jobs tables. After correcting queue setup, clear cached configuration and start a worker:

```bash
php artisan config:clear
php artisan queue:work --tries=1 --timeout=3600
```

If an existing worker loaded old configuration, stop and restart that worker. Review the current task state before submitting another request. Duplicate queue requests cannot execute the same task concurrently, but a rejected request can still appear as a failed queue job.

CLI execution through `molly:start TASK_ID` does not require a queue worker.

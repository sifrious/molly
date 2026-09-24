# Troubleshooting

Read the saved evidence before trying again. Most problems name themselves in doctor or in the run report:

```bash
php artisan molly:doctor --json
php artisan molly:task TASK --json
php artisan molly:show RUN_ID --verbose
```

## Doctor fails

After any change to `.env` or `config/molly.php`:

```bash
php artisan config:clear
php artisan molly:doctor
```

Restart queue workers after configuration changes too.

| Code | What to do |
| --- | --- |
| `migration_missing` | Run `php artisan migrate`. |
| `database_unavailable` | Fix the application's database connection. |
| `pest_missing` | Install Pest in the workspace. See [Compatibility](compatibility.md). |
| `sandbox_unavailable` | The host cannot isolate the writer and verifier. See [Sandbox unavailable](#sandbox-unavailable). |
| `sandbox_unsafe_override` | Isolation is off by your choice. Keep this to trusted checkouts. |
| `parallel_process_groups_unavailable` | Install POSIX support, or set `parallel_checks` to `false`. |
| `model_not_configured` | Run `molly:setup --agent=ollama --model=NAME`. |
| `model_not_local` | Choose an installed local model, not a hosted model name. |
| `ollama_unreachable` | Start Ollama (`ollama serve`) or fix `OLLAMA_URL`. Unreachable is not the same as a missing model. |
| `model_missing` | Ollama is up. Pull the model or fix `MOLLY_LOCAL_MODEL`. |
| `ollama_config_invalid` | Use a loopback HTTP URL, the Ollama driver, and a positive `molly.timeout`. |
| `amp_unavailable` | Run `amp usage` and log in to Amp. |
| `clever_disabled` | Use the `local` or `testing` environment, or remove an explicit `false` in `config/molly-complexity.php`. |
| `clever_unavailable` | Check the package install and the application log. |
| `jev_capability_missing` | Jev is on, but `laravel/ai` cannot classify. Pin the accepted commit or turn Jev off. See [Jev](reference/configuration.md#jev). |
| `jev_unconfigured` | Jev is on and capable, but `TYPESAFE_API_KEY` is empty or `config/ai.php` predates the TypeSafe provider. |

Informational codes such as `ollama_endpoint`, `jev_disabled`, and `jev_ready` pass.

## Sandbox unavailable

On Linux, Molly runs the writer and the Pest verifier in a Landlock sandbox with private user and network namespaces. Doctor reports it as the Sandbox check. `sandbox_unavailable` means the host lacks those features, which is always true on macOS, and `molly:start` then refuses with `SANDBOX_UNAVAILABLE`.

For a checkout you trust, turn isolation off:

```dotenv
MOLLY_SANDBOX_ALLOW_UNSAFE=true
```

Run `php artisan config:clear` and `php artisan molly:doctor`. Doctor now reports `sandbox_unsafe_override` and passes. The writer and Pest run with your user's permissions from then on, so do not use this for repositories you do not trust or on shared machines. Molly still limits proposals to the allowed files.

A task that already failed on the sandbox check is `failed`. Fix the host, then `php artisan molly:retry TASK`.

## Composer cannot find Molly

Molly is not on Packagist yet. Composer needs the repository line:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
```

If the requirement still fails, check the PHP Composer is using and the Laravel version:

```bash
php --version
composer show laravel/framework
composer check-platform-reqs
```

Molly needs PHP 8.3 or later and Laravel 12 or 13. Install a tagged release, not a branch.

## Pest fails

Read the recorded output, then run the test yourself:

```bash
php artisan molly:show RUN_ID --verbose
vendor/bin/pest tests/Feature/YourTest.php
```

| Reason | Meaning |
| --- | --- |
| `tests_failed` | An assertion failed or a test errored. |
| `no_tests` | Pest found no test in the required file. Check for `<?php` and an `it()` or `test()` call. |
| `no_assertions` | A test ran but asserted nothing. |
| `tests_skipped_or_incomplete` | Skipped or incomplete tests are not passing evidence. |
| `test_timeout` | Pest ran longer than `molly.test_timeout`. |
| `test_process_failed` | The Pest process exited unsuccessfully. |
| `junit_missing` or `junit_invalid` | Molly cannot trust the report. |
| `false_green` | The report passed without naming the required file. |

Do not weaken the assertions to get a retry through.

## Tarpit blocks completion

Read the finding: file, line, problem, classification, and recommendation. Only unresolved accidental complexity blocks. `REVIEW_INVALID` means the model returned an incomplete or inconsistent review, so Molly has no review evidence; small models do this often, and a larger one usually fixes it. A passing review never overrides a failed test.

## The model is slow or returns bad changes

The proposal and review requests each get `molly.timeout` seconds (180 by default). Try a smaller task before raising it.

| Failure | Meaning |
| --- | --- |
| `GENERATION_INVALID` | The model's output did not match the proposal format. |
| `CHANGES_INVALID` | The proposal was empty, repeated a file, or named a file outside the allowed list. |
| `FILE_TOO_LARGE` | A selected file or replacement is over `molly.max_file_bytes`. |
| `NO_CHANGES` | The proposal left the selected files as they were. |
| `TEST_AUTHORING_INVALID` | A written test is not a Pest file Molly can run: missing `<?php`, PHPUnit classes, or routes and schema inside the test. |

## A parallel check fails

`molly:show RUN_ID --verbose` names the branch. `branch_start_failed`, `branch_timeout`, `branch_cancelled`, `branch_result_invalid`, and `CHECK_PROCESS_GROUP_UNAVAILABLE` all mean one branch did not produce valid evidence. Both branches must; one passing branch cannot stand in for the other. If process groups are the problem, set `parallel_checks` to `false`.

## A task is stuck in running

```bash
php artisan molly:task TASK
php artisan molly:stop TASK
php artisan molly:task TASK
```

`WORKSPACE_BUSY` means another run holds the workspace lock. Check whether it is still working before doing anything else. Do not delete files under `.molly/` to force a retry; an expired lease is recovered on its own.

## Retry is rejected

`molly:start` is for pending tasks and `molly:retry` for failed or stopped ones. `ATTEMPT_LIMIT_REACHED` means the task used its attempts (three by default). `COMMAND_ALREADY_SUCCEEDED` means the task already completed. `php artisan molly:advice TASK` says what is allowed.

## The web interface returns 404 or no run appears

Check `.env`:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
```

Then `php artisan config:clear`. For runs started from the browser, make sure a worker is running and look at `php artisan queue:failed`. Artisan starts need no worker.

## Workspace changed during a run

`WORKSPACE_CHANGED` means a selected file no longer matched what Molly expected. Avoid editing selected files while a run is active. A failed verification does not restore the applied proposal; check `git diff` before retrying.

## Jev did not answer

Advice and plan pages say why. `jev_disabled` is the default. `capability_missing` means `laravel/ai` in your application has no classification API, `invalid_config` means the key is missing, `provider_error` means the request failed, and `low_confidence` means Jev answered below the threshold and Molly kept its own guidance. Doctor reports the first three. See [Jev](reference/configuration.md#jev).

## Still stuck

- [Commands](reference/commands.md)
- [Configuration](reference/configuration.md)
- [Verification](verification.md)

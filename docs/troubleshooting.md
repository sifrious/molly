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
| `database_unwritable` | Doctor inserted a row inside a transaction, and the database refused it. Free disk space, or make the database file and its directory writable. Doctor rolls the row back. |
| `pest_missing` | Install Pest in the workspace. See [Compatibility](compatibility.md). |
| `git_missing` | Install Git or add it to `PATH`. Without Git, `molly:setup`, `molly:project-init`, `molly:project-new`, `molly:create`, `molly:demo`, `molly:run`, and `molly:review-commit` fail with `GIT_MISSING` before they write anything. `molly:status` reports `git_missing` in its readiness checks and still exits `0`. |
| `workspace_not_git` | The workspace is not a Git repository. Molly records the commit each task starts from and never creates a repository or a commit for you. See [Not a Git repository](#not-a-git-repository). |
| `workspace_revision_missing` | The workspace is a Git repository with no commit yet, or the HEAD commit holds none of its files, such as an untracked `backend/` in a committed monorepo. Commit the app in the repository that holds it, then run doctor again. |
| `sandbox_unavailable` | The host cannot isolate the writer and verifier. See [Sandbox unavailable](#sandbox-unavailable). |
| `sandbox_unsafe_override` | Isolation is off by your choice. Keep this to trusted checkouts. |
| `parallel_process_groups_unavailable` | Install POSIX support, or set `parallel_checks` to `false`. |
| `model_not_configured` | Run `molly:setup --agent=ollama --model=NAME`. |
| `model_not_local` | Choose an installed local model, not a hosted model name. |
| `ollama_unreachable` | Start Ollama (`ollama serve`) or fix `OLLAMA_URL`. Unreachable is not the same as a missing model. |
| `model_missing` | Ollama is up. Pull the model or fix `MOLLY_LOCAL_MODEL`. |
| `model_exceeds_memory` | The model's size plus `molly.memory.headroom_gb` is more than the available memory `molly:preflight` measures, and Ollama does not hold the model yet. Choose a smaller installed model with `molly:setup`, or free memory. See [Memory](reference/configuration.md#memory). |
| `memory_headroom_invalid` | Set `molly.memory.headroom_gb` to a number of gigabytes, 0 or more. |
| `ollama_config_invalid` | Use a loopback HTTP URL, the Ollama driver, and a positive `molly.timeout`. |
| `amp_unavailable` | Run `amp usage` and log in to Amp. |
| `clever_disabled` | Use the `local` or `testing` environment, or remove an explicit `false` in `config/molly-complexity.php`. |
| `clever_unavailable` | Check the package install and the application log. Runs still proceed and record Clever as `unavailable`. |
| `jev_capability_missing` | Jev is on, but `laravel/ai` cannot classify. Pin the accepted commit or turn Jev off. See [Jev](reference/configuration.md#jev). |
| `jev_unconfigured` | Jev is on and capable, but `TYPESAFE_API_KEY` is empty or `config/ai.php` predates the TypeSafe provider. |

Informational codes such as `git_repository`, `ollama_endpoint`, `model_fits_memory`, `model_loaded`, `jev_disabled`, and `jev_ready` pass. `model_memory_unknown` has the status `unknown`: Molly could not measure the available memory, the model's size, or the loaded models, so it does not refuse the model, and doctor can still be ready. `molly:preflight` shows which fact is unknown and why.

On a full disk or a read-only path, commands fail with a code, the path they could not write, and the reason the system gave, such as `No space left on device` or `Permission denied`:

| Code | Path |
| --- | --- |
| `ENV_UNWRITABLE` | `.env`, from `molly:setup`. `.env` is left unchanged. |
| `DIRECTORY_UNWRITABLE` | A directory Molly could not create, such as `.molly`. |
| `WORKER_START_FAILED` | `.molly/worker`. |
| `GITIGNORE_UNWRITABLE` | `.gitignore`, from `molly:project-init`. |
| `CONFIG_UNWRITABLE` | `config/molly.php`, from `molly:project-init`. |
| `WORKSPACE_IDENTITY_UNWRITABLE` | `.molly/identity.json`. |
| `PROJECT_RECORD_UNWRITABLE` | `.molly/project.json`. |
| `PROJECT_INDEX_UNWRITABLE` | `projects.json` in `MOLLY_HOME`. |
| `DATABASE_UNWRITABLE` | The application database, from `molly:create`. No task is saved. For `molly:project:index` and `molly:knowledge:index`, the knowledge database, `.molly/knowledge.sqlite` by default. |
| `JOURNAL_WRITE_FAILED` | `.molly/JOURNAL.md`, `.molly/GLOSSARY.md`, a file in `.molly/journal`, or a handoff envelope in `.molly/handoffs`. A handoff that cannot be saved is not recorded. `molly:journal --project --json` keeps its `status: unavailable` document on stdout and prints the coded line on stderr. |
| `KNOWLEDGE_MANIFEST_UNWRITABLE` | `.molly/graphs/manifest.json`, from `molly:graphs-bootstrap`. A missing `.molly/graphs` directory fails with `DIRECTORY_UNWRITABLE`. |
| `SETTINGS_UNWRITABLE` | `settings.json` in `MOLLY_HOME`, from `molly:settings-set`. The file is left unchanged. |

## Not a Git repository

Commands that create or run a task refuse a workspace that is not a Git repository: `molly:create`, `molly:demo`, `molly:story`, `molly:start`, `molly:retry`, GitHub issue import, and the web and MCP task tools. They print:

```text
WORKSPACE_NOT_GIT: /path/to/app is not a Git repository. Run git init and commit your work, then try again.
```

Molly refuses before it writes anything, so no task, `.molly` directory, or demo file is created. `molly:start` and `molly:retry` check, in order, the task's state and inputs, `GIT_MISSING`, `WORKSPACE_NOT_GIT` or `WORKSPACE_REVISION_MISSING`, and then `SANDBOX_UNAVAILABLE`, all before they take the task lock. When the workspace already has `.molly/lifecycle.jsonl`, the refusal is appended to it as a `start_refused` event; otherwise the command only prints the error. `composer create-project laravel/laravel` and `molly:project-new` do not create a repository. Commit the application yourself, with your own Git identity:

```bash
git init
git add -A
git commit -m "Start"
```

The application does not have to be the repository root. A Laravel app in `backend/` of a repository rooted one level up is accepted, and so is a linked worktree or a submodule, where `.git` is a file. Molly asks Git for the top level with `git rev-parse --show-toplevel`. The workspace stays the app directory: tasks, `.molly/`, and the protected test paths belong to the app, while the revision, branch, and checkout kind come from the repository that contains it. Do not run `git init` inside an app that already sits in a repository. A directory the enclosing repository ignores, such as a scratch copy under an ignored path, is refused with `WORKSPACE_NOT_GIT` and a message naming the repository that ignores it, because its files are in no commit.

A repository with no commit fails with `WORKSPACE_REVISION_MISSING` until the first commit exists, and the same commands write nothing. So does an app whose files are in no commit of the repository that holds it, such as an untracked, not ignored `backend/`: Molly checks `git ls-tree HEAD` under the app and prints the `git -C <repository> add <path>` and `git -C <repository> commit` commands to run. When Git itself is missing, commands fail with `GIT_MISSING` first. `molly:doctor` and `molly:status` report `workspace_not_git` in the `Git repository` check and never create a repository; `molly:status` still exits `0`.

## .molly is a link

Molly keeps its graph store, manifest, journal, worker record, identity, and receipts in real files under the workspace's `.molly` directory. When `.molly`, or a file or directory inside it, is a symbolic link or resolves outside the workspace, commands fail with `WORKSPACE_PATH_ESCAPE`, name the link and its target, and write nothing. Replace the link with a real directory or file and run the command again.

## Sandbox unavailable

On Linux, Molly runs the writer and the Pest verifier in a Landlock sandbox with private user and network namespaces. Doctor reports it as the Sandbox check. `sandbox_unavailable` means the host lacks those features, which is always true on macOS, and `molly:start` then refuses with `SANDBOX_UNAVAILABLE`.

For a checkout you trust, turn isolation off:

```dotenv
MOLLY_SANDBOX_ALLOW_UNSAFE=true
```

Run `php artisan config:clear` and `php artisan molly:doctor`. Doctor now reports `sandbox_unsafe_override` and passes. The writer and Pest run with your user's permissions from then on, so do not use this for repositories you do not trust or on shared machines. Molly still limits proposals to the allowed files.

A start refused with `SANDBOX_UNAVAILABLE` saves no run and leaves the task `pending`. Fix the host or the setting, then run `php artisan molly:start TASK` again. Tasks that failed this way in earlier versions are `failed`; use `php artisan molly:retry TASK` for those.

## Composer cannot find Molly

Molly is not on Packagist yet. Composer needs the repository line:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.2
```

If the requirement still fails, check the PHP Composer is using and the Laravel version:

```bash
php --version
composer show laravel/framework
composer check-platform-reqs
```

Molly needs PHP 8.3 or later and Laravel 12 or 13. Install a tagged release, not a branch.

`^0.2` does not resolve until `v0.2.0` is tagged; the latest tag is `v0.1.3`. [Release status](getting-started.md#release-status) lists what to install until then.

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

## The authored test cannot run

After a test-authoring run, `molly:start` and `molly:task` print `Authored test check: bootstrap_error` when some tests cannot run, with each cause and the affected tests. `molly:lock-test --approve` refuses such a test with `AUTHORED_TEST_BROKEN` and locks nothing. With `--json`, the error document adds `authored_test`, the check, and `next`, the command and reason. Run the printed command, usually:

```bash
php artisan molly:retry TASK
```

The retry sends the model the cause and Molly's guidance for it in `previous_attempt.authored_test`. The model may change only the test file, so the guidance puts every fix there:

| Cause | Meaning | Guidance sent to the model |
| --- | --- | --- |
| `database_not_migrated` | A test hit a missing table, and neither the test file nor `tests/Pest.php` applies `RefreshDatabase`. | Add `uses(RefreshDatabase::class);` to the test file. |
| `test_case_not_bound` | Laravel helpers such as `get()` are undefined because the file is not bound to `Tests\TestCase`. | Add `uses(Tests\TestCase::class);` to the test file. |
| `test_support_missing` | A test helper class or Pest function is not loaded. | Import it or stop depending on it. |
| `parse_error` | The file does not parse. | Return one complete PHP file. |
| `no_tests` | Pest found no tests in the file. | Declare cases with `it()` or `test()`. |
| `no_assertions` | The tests asserted nothing. | Assert the expected behavior. |
| `tests_skipped` | Pest skipped the tests or marked them incomplete. | Remove `skip()` and `todo()`. |

When a test that already applies `RefreshDatabase` hits a missing table, Molly counts it as missing behavior: the implementation has to add the migration.

You can also fix the test yourself and run `molly:lock-test TASK --approve` again. The lock runs the test again before it locks anything. When the same cause keeps coming back, the retry stops at `molly.repair.per_failure` with `REPAIR_BUDGET_EXHAUSTED`, and the message tells you to edit the test and lock it.

`pest_run_failed` means Pest did not finish a usable run for a reason outside the test, such as `pest_missing` or `test_timeout`. A rewrite will not fix that, so Molly does not block the lock on it; see [Pest fails](#pest-fails).

## The story has no implementation files

`SCOPE_EMPTY` from `molly:story` means the model named no file Molly may change. The message lists each path it named and why Molly dropped it. Molly lets the implementation change files under `app/`, `routes/`, `resources/`, and `tests/` only, so a story that the model answers with only `config/` or `database/` paths ends here. No task is saved. Run the story again, reword it to name the page or route, or pass the files yourself:

```bash
php artisan molly:story 'STORY' --test=tests/Feature/StoryTest.php --file=routes/web.php
```

## The lock has no implementation files

`SCOPE_REQUIRED` from `molly:lock-test` means the task has no files for the implementation: it has no files derived from a story and you passed no `--file`. The test stays unlocked. The message ends with a command that names existing files from `routes/`, `app/`, and `resources/views/`. Check those files, change them if needed, and run it:

```bash
php artisan molly:lock-test TASK --approve --file=routes/web.php
```

## Tarpit blocks completion

Read the finding: file, line, problem, classification, and recommendation. Only unresolved accidental complexity blocks. `REVIEW_INVALID` means the model returned an incomplete or inconsistent review, so Molly has no review evidence; small models do this often, and a larger one usually fixes it. A passing review never overrides a failed test.

## The model is slow or returns bad changes

The proposal and review requests each get `molly.timeout` seconds (180 by default). A request that runs longer fails with `PROVIDER_TIMEOUT`, described in [The model request fails](#the-model-request-fails). Try a smaller task before raising it.

| Failure | Meaning |
| --- | --- |
| `GENERATION_INVALID` | The model's output did not match the proposal format. |
| `CHANGES_INVALID` | The proposal was empty, repeated a file, or named a file outside the allowed list. |
| `FILE_TOO_LARGE` | A selected file or replacement is over `molly.max_file_bytes`. |
| `NO_CHANGES` | The proposal left the selected files as they were. |
| `TEST_AUTHORING_INVALID` | A written test is not a Pest file Molly can run: missing `<?php`, PHPUnit classes, or routes and schema inside the test. |

## The model request fails

`molly:story`, `molly:start`, and the Tarpit review send every local model request through one place, so a provider failure reads the same from each of them. The request fails before anything is saved; a run records the message as its error.

| Code | Meaning | What to do |
| --- | --- | --- |
| `MODEL_MEMORY_INSUFFICIENT` | Ollama does not hold the model yet, and its size plus `molly.memory.headroom_gb` is more than the available memory. The message gives all three numbers. Molly refused before sending the request, so Ollama loaded nothing. | Choose a smaller installed model with `php artisan molly:setup`, or free memory and try again. See [Memory](reference/configuration.md#memory). |
| `MEMORY_HEADROOM_INVALID` | `molly.memory.headroom_gb` is not a number of gigabytes, 0 or more. | Fix the value in `config/molly.php`, then run `php artisan config:clear`. |
| `MODEL_MISSING` | Ollama answered HTTP 404 because it has no model with the configured name. | Run `ollama list`, then choose an installed model with `php artisan molly:setup`. Molly never pulls a model. |
| `PROVIDER_ERROR` | Ollama answered with an HTTP error, such as 500, 503, or 429, or with its own `error` field in place of a chat response. The message gives the HTTP status and the model name, not Ollama's text. | Read the Ollama server log: the terminal running `ollama serve`, or `~/.ollama/logs/server.log` for the macOS app. Fix the cause, then try again. |
| `PROVIDER_RESPONSE_INVALID` | Ollama answered, but the body was not a chat response: truncated JSON, plain text, an empty object, or fields Laravel AI cannot read. Molly uses none of it. | Check that `OLLAMA_URL` points at Ollama itself, then try again. |
| `PROVIDER_TIMEOUT` | Ollama accepted the request but did not answer within `molly.timeout`. The message names the model and the timeout in seconds. | Try a smaller task or a faster model. If you raise `molly.timeout` in `config/molly.php`, run `php artisan config:clear`. |
| `PROVIDER_UNREACHABLE` | Molly could not connect to Ollama at the configured URL, for example because the connection was refused. The message names the URL. | Start Ollama with `ollama serve`, or fix `OLLAMA_URL`. `molly:doctor` reports the same condition as `ollama_unreachable`. |

These messages never include a PHP class name, a file path, or the provider's raw reply. A reply that is a valid chat response but holds the wrong answer shape fails later, with `ACCEPTANCE_INVALID`, `GENERATION_INVALID`, or `REVIEW_INVALID`.

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

`molly:start` is for pending tasks and `molly:retry` for failed or stopped ones. `ATTEMPT_LIMIT_REACHED` means the task used its attempts (three by default). `REPAIR_BUDGET_EXHAUSTED` means the same failure came back `molly.repair.per_failure` times; for an authored test that cannot run, edit the test and lock it. `COMMAND_ALREADY_SUCCEEDED` means the task already completed. `php artisan molly:advice TASK` says what is allowed.

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

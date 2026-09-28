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
| `workspace_revision_missing` | The workspace is a Git repository with no commit yet. Commit your work, then run doctor again. |
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

Informational codes such as `git_repository`, `ollama_endpoint`, `jev_disabled`, and `jev_ready` pass.

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
| `JOURNAL_WRITE_FAILED` | `.molly/JOURNAL.md`, `.molly/GLOSSARY.md`, or a file in `.molly/journal`. `molly:journal --project --json` keeps its `status: unavailable` document on stdout and prints the coded line on stderr. |
| `KNOWLEDGE_MANIFEST_UNWRITABLE` | `.molly/graphs/manifest.json`, from `molly:graphs-bootstrap`. A missing `.molly/graphs` directory fails with `DIRECTORY_UNWRITABLE`. |
| `SETTINGS_UNWRITABLE` | `settings.json` in `MOLLY_HOME`, from `molly:settings-set`. The file is left unchanged. |

## Not a Git repository

Commands that create or run a task refuse a workspace that is not a Git repository: `molly:create`, `molly:demo`, `molly:story`, `molly:start`, `molly:retry`, GitHub issue import, and the web and MCP task tools. They print:

```text
WORKSPACE_NOT_GIT: /path/to/app is not a Git repository. Run git init and commit your work, then try again.
```

Molly refuses before it writes anything, so no task, `.molly` directory, or demo file is created. `composer create-project laravel/laravel` and `molly:project-new` do not create a repository. Commit the application yourself, with your own Git identity:

```bash
git init
git add -A
git commit -m "Start"
```

The application does not have to be the repository root. A Laravel app in `backend/` of a repository rooted one level up is accepted, and so is a linked worktree or a submodule, where `.git` is a file. Molly asks Git for the top level with `git rev-parse --show-toplevel`. The workspace stays the app directory: tasks, `.molly/`, and the protected test paths belong to the app, while the revision, branch, and checkout kind come from the repository that contains it. Do not run `git init` inside an app that already sits in a repository. A directory the enclosing repository ignores, such as a scratch copy under an ignored path, is refused with `WORKSPACE_NOT_GIT` and a message naming the repository that ignores it, because its files are in no commit.

A repository with no commit fails with `WORKSPACE_REVISION_MISSING` until the first commit exists. When Git itself is missing, commands fail with `GIT_MISSING` first. `molly:doctor` and `molly:status` report `workspace_not_git` in the `Git repository` check and never create a repository; `molly:status` still exits `0`.

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

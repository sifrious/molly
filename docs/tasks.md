# Tasks

A task is a saved request: what should change, which Pest test proves it, and which files the agent may edit. A run is one attempt to complete that task. This page covers creating, starting, inspecting, retrying, and stopping tasks from Artisan.

## Create a task

```bash
php artisan molly:create
```

Molly asks for the request, an optional nickname, the required Pest test, and the other files it may change. The same task from a script:

```bash
php artisan molly:create \
  'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.' \
  --name=ready-check \
  --test=tests/Feature/ReadyTest.php \
  --file=routes/web.php \
  --json --no-interaction
```

Creating a task saves it and nothing else. The model is not called and no file changes.

The test must already exist. Molly records its digest and protects it from the agent for the life of the task. If you want the agent to write the test, see [Write the test first](tutorials.md#write-the-test-first).

### File scope

Molly accepts paths under `app/`, `routes/`, `resources/`, and `tests/`. By default a task may name up to eight writable files, each up to 64 KB, and the protected test does not count toward that limit. Hidden paths, symlinks, directories, and paths that leave the workspace are rejected. `--workspace=PATH` points the task at another checkout; the default is the application itself.

## Start from a story

`molly:story` turns a plain-English story into a test-authoring task:

```bash
php artisan molly:story 'Guests see "Hello stranger". Signed-in users see "Hello world".' \
  --test=tests/Feature/HelloTest.php
```

The configured model returns three things, and Molly saves each on the task:

- Numbered acceptance criteria, in `source.acceptance`.
- The application files the implementation will create or change, in `source.scope.files`. Molly checks each path with the [file scope](#file-scope) rules and keeps the ones that pass. A path it drops, such as `config/app.php`, a migration, or the test itself, is listed in `source.scope.rejected` with the reason. The authoring run cannot write these files; it writes only the test.
- The Composer packages the behavior needs, in `source.scope.required_packages`. Molly compares each name with the `require` list in `composer.json` and with `composer.lock`, and marks it `required`, `dev_only`, `transitive`, or `missing`.

The command prints the criteria, the files, and three commands. Run them as printed:

```bash
php artisan molly:start TASK
php artisan molly:lock-test TASK --approve
php artisan molly:start TASK
```

The first run writes the test. When it finishes, Molly runs the test and checks why it fails. The check is saved on the run as `report.authored_test`, and `molly:start` and `molly:task` print it with the next command:

| Classification | What Molly prints next |
| --- | --- |
| `missing_behavior` | The tests fail because the behavior is not built yet. Review the test, then run `php artisan molly:lock-test TASK --approve`. |
| `bootstrap_error` | Some tests cannot run. Molly names each cause, such as `database_not_migrated`, lists the affected tests, and prints `php artisan molly:retry TASK`. The retry sends the model Molly's guidance for that cause. |
| `already_passing` | The test passed before any implementation, so it proves nothing. Molly still prints the lock command, and the implementation run then refuses with `RED_BASELINE_INVALID`. Edit the test so it asserts the new behavior, then lock it again. |

For example, a test that creates a user in a fresh Laravel app fails with `no such table: users` when neither the test file nor `tests/Pest.php` applies `RefreshDatabase`. That is `database_not_migrated`, not missing behavior, and the retry asks the model to add `uses(RefreshDatabase::class);` to the test file. The authoring run also gives the model `tests/Pest.php` to read, so it can see which `TestCase` and traits apply to every test. See [Troubleshooting](troubleshooting.md#the-authored-test-cannot-run) for each cause.

Read the test before the second command. `molly:lock-test --approve` locks the test, allows the implementation to change the derived files, prints them, and records the RED baseline. Pass `--file` to replace the derived files with your own. The last command runs the implementation against the locked test.

When a package is not in `require`, `molly:story` prints a command such as `composer require livewire/livewire`. Run it before the implementation run, which cannot add packages. Molly never runs Composer for you. Molly depends on `livewire/livewire`, so when Molly is installed with `--dev`, Livewire is present during development but shows as `dev_only`: your application does not declare it, and a production install leaves it out.

When the model names no usable file, `molly:story` fails with `SCOPE_EMPTY` and saves no task. See [Troubleshooting](troubleshooting.md#the-story-has-no-implementation-files).

## Start a task

```bash
php artisan molly:start ready-check
```

The run happens in your terminal: the model proposes a change, Molly applies it, runs the test, reviews the diff, and records the result. The command prints the run ID and exits `0` when the run completed, `1` otherwise. No queue worker is needed for Artisan.

Molly refuses to start a second run in the same workspace while one is active (`WORKSPACE_BUSY`), and refuses to start a task that already completed.

You do not need a clean working tree. A run writes only the task's selected files. Other edits, staged changes, and untracked files stay exactly as they were, and Molly never stages or commits. When a write fails partway, Molly restores the selected files it had written, with their original contents and file modes.

## Inspect tasks and runs

```bash
php artisan molly:tasks
php artisan molly:task ready-check
php artisan molly:show RUN_ID --verbose
php artisan molly:receipt RUN_ID
```

`molly:tasks` lists the newest tasks. `molly:task` shows one task, its display status (for example `awaiting_approval` after a completed run), any recorded pull request, and every attempt. `molly:show` reads a run's evidence, and `molly:receipt` reads its verification receipts. Reading never calls the model.

### Task and run identity

Every task and run in one checkout carries the same `project_id`, `workspace_id`, `repository_id`, and `checkout_id`. Molly creates these UUIDs the first time it binds the checkout, stores them in `.molly/identity.json`, and reads them back on every later command. When the checkout is a registered project, `project_id` is the ID from `.molly/project.json` and `molly:projects`. `base_sha` records the Git HEAD when the task was created; on a run it records the HEAD when that run started.

Molly never rewrites the IDs of saved rows. Tasks created before this file existed keep the IDs they were given. A damaged `.molly/identity.json` stops task creation with `WORKSPACE_IDENTITY_INVALID` instead of minting new IDs.

`molly:inspect` follows the links between a task, its runs, and the saved conversations:

```bash
php artisan molly:inspect ready-check --ensure-conversations
php artisan molly:inspect --run=RUN_ID
```

## Retry a failed task

```bash
php artisan molly:retry ready-check
```

A retry creates another run and keeps the earlier one. Molly sends the model a bounded summary of the previous failure. A task may make three attempts in total by default, counted across starts and retries; the limit is `molly.max_attempts`. Each failed run also records `failure_fingerprint`, a digest of the failing verifiers, error code, failing test names, and blocking Tarpit codes. Message wording is left out, so a reworded failure keeps its fingerprint. When the latest fingerprint has failed `molly.repair.per_failure` times (3 by default), Molly refuses another attempt with `REPAIR_BUDGET_EXHAUSTED`. A test-authoring run whose test cannot run is fingerprinted by the causes of its authored test check alone, so a model that renames tests but keeps the same mistake reaches the budget too. The message then names the cause and tells you to edit the test and lock it. Molly never retries on its own.

Read the failed run before retrying. If the failure is in the test itself, fix the test in a new task rather than weakening it.

## Stop a task

```bash
php artisan molly:stop ready-check
```

A pending task stops immediately. A running task is asked to stop at its next step, and Molly records the request. Applied edits may already be in the working tree, so check `git status` afterward. A stopped task can be retried.

## Name a task

```bash
php artisan molly:name TASK_UUID ready-check
php artisan molly:name old-name new-name
```

A nickname starts with a letter and uses lowercase letters, digits, and hyphens. Renaming keeps the UUID and the run history.

## Ask what to do next

```bash
php artisan molly:advice ready-check
```

Advice reads the saved state and the attempt limit and tells you which action is allowed: start, inspect, retry, stop, or done. It never performs the action. [Task advice](task-advice.md) covers the optional Jev suggestion.

## Approve, then record the pull request

After a run completes, the task waits for a person:

```bash
php artisan molly:approve ready-check --approve
php artisan molly:pr-body ready-check
```

`molly:approve` records that you reviewed the change. `molly:pr-body` then prints a pull request description built from the evidence. Molly does not open or merge pull requests. When a person does, record it:

```bash
php artisan molly:pr-opened ready-check --url https://github.com/OWNER/REPO/pull/123 --approve
php artisan molly:merged ready-check --sha MERGE_SHA --approve
```

## Task states

| State | Meaning |
| --- | --- |
| `pending` | Saved and ready to start. |
| `running` | An attempt is in progress. |
| `completed` | The required checks passed. Review the diff before committing. |
| `failed` | The attempt ended without the evidence needed to complete. |
| `stopped` | Work stopped before completion. |

When `molly:start` or `molly:retry` is refused before an attempt begins, for example with `GIT_MISSING`, `SANDBOX_UNAVAILABLE`, `RED_BASELINE_MISSING`, `WORKSPACE_BUSY`, or `PROTECTED_TEST_MISSING`, no run is saved. The task keeps its state and attempt count, and `.molly/lifecycle.jsonl` gets one `start_refused` event with the code and message when that file already exists. The state, input, Git, and sandbox checks run before Molly takes the task lock, so those refusals never create `.molly/`. `WORKSPACE_BUSY` and `BASELINE_MISSING` come after the claim, which is then written back exactly as it was. Fix the cause and run the same command again. A failure after the run is saved still marks the task `failed`.

### Recovery after a crash

A process that is killed, for example with `kill -9`, or that dies with its host cannot record anything, so its task and run stay `running`. Molly recovers such a task in two cases:

- Its lease expired. A live run renews the lease at every step, for longer than the slowest step (`molly.timeout` or `molly.test_timeout`, plus 30 seconds).
- The claim came from a process on this host, and no process holds the task's lock in `.molly/`. A live run holds that lock for its whole attempt, so a free lock means the process exited. Molly checks this when `molly:start` or `molly:retry` takes the lock. A worker ID set in `molly.agent_bus.worker_id` does not name a host, so those claims wait for the lease.

Recovery marks the task `failed` and retryable, or `stopped` when a stop had been requested, and clears the claim. Each run the dead process left `running` gets the same status, an `error` that starts with `RUN_ABANDONED:` and names the worker, and a `recovery` entry with the `reason` (`lease_expired` or `worker_exited`), `worker_id`, and `recovered_at`. The run keeps the evidence it had saved. When `.molly/lifecycle.jsonl` exists, the recovery appends a `failed` or `stopped` event with the same `reason`, the old `worker_id`, and the recovered run IDs, so `molly:task` and MCP `molly_task` show the recovered status too.

So after a crash, run `php artisan molly:retry TASK`. The retry recovers the task, then starts a new attempt from the recorded baseline. `molly:start` recovers it too, then refuses with `TASK_NOT_PENDING`, and the task stays `failed` and retryable. Nothing runs the task again on its own. A queued start job that a killed worker had reserved is delivered again after the queue's `retry_after`, and Laravel fails it with `MaxAttemptsExceededException` because Molly's jobs allow one attempt.

`molly:task` also shows a display status that folds in approvals and pull requests: `awaiting_approval`, `approved`, `handed_off`, and `merged`.

## One-off runs

To make a change without saving a reusable task:

```bash
php artisan molly:run 'Rename the greeting method.' \
  --test=tests/Feature/GreetingTest.php \
  --file=app/Greeting.php
```

The run saves a report but cannot be retried or stopped later.

## Next

- [Verification](verification.md)
- [Task advice](task-advice.md)
- [Tutorials](tutorials.md)
- [Commands](reference/commands.md)

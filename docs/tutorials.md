# Tutorials

Each tutorial takes one workflow beyond the demo. They assume [Getting started](getting-started.md) is done and `molly:doctor` passes.

## Write the test first

Most tasks start from a Pest test you wrote. When you would rather describe the behavior and have Molly write the test, use two tasks: one that may edit the test, then one that may not.

Create the authoring task. `--allow-test-edits` lets the agent create or change the named test, and nothing else in `tests/`:

```bash
php artisan molly:create \
  'Write the Pest acceptance test for GET /ready returning HTTP 200 and exactly {"ready":true}. Do not implement the route.' \
  --name=ready-test \
  --test=tests/Feature/ReadyTest.php \
  --file=routes/web.php \
  --allow-test-edits
php artisan molly:start ready-test
```

This run is meant to fail: the test exists now and the route does not. Read it to make sure the failure is the right one, a 404 rather than a syntax error:

```bash
php artisan molly:show RUN_ID --verbose
cat tests/Feature/ReadyTest.php
```

Molly checks the written file before running it and rejects a PHPUnit class or a file without `<?php` with `TEST_AUTHORING_INVALID`. Small local models trip on this; see [Choosing a model](ollama-quickstart.md#choosing-a-model).

When the test says what you meant, lock it. This records its digest, marks the lock as your approval, and turns the same task into the implementation task with a fresh attempt budget:

```bash
php artisan molly:lock-test ready-test --approve --file=routes/web.php \
  --reason='The test names the exact JSON body and status.'
php artisan molly:start ready-test
```

The second run may change only `routes/web.php`. If the model touches the test, the proposal is rejected before it is applied.

## From a GitHub issue to a task

With the `gh` CLI logged in, an issue can become a pending task:

```bash
php artisan molly:import https://github.com/OWNER/REPO/issues/42 \
  --name=issue-42 \
  --test=tests/Feature/Issue42Test.php \
  --file=app/Services/Example.php
```

Molly reads the issue title and body and saves them with the task. It does not start the task or write anything to GitHub. Write the test (or use the [test-first flow](#write-the-test-first) with `--allow-test-edits`), then start the task as usual.

After a completed run and your review, record the approval and let Molly write the pull request text:

```bash
php artisan molly:approve issue-42 --approve
php artisan molly:pr-body issue-42 --close
php artisan molly:comment issue-42 --approve
```

`molly:pr-body` prints a description that links the issue, the test, and the evidence, with "Closes #42" when you pass `--close` after the checks passed. `molly:comment` posts one status comment on the issue. Each writes to GitHub only after `--approve`. Opening and merging the pull request stays with you; `molly:pr-opened` and `molly:merged` record what you did.

## Before-and-after evidence for a component

When a task touches a Livewire component or a Blade view, Molly records a hash of each selected file before and after the run, so the report shows which files changed even when the diff is long:

```bash
php artisan molly:create 'Show a retry button after a failed run.' \
  --name=retry-button \
  --file=app/Livewire/RunStatus.php \
  --file=resources/views/livewire/run-status.blade.php \
  --test=tests/Feature/RunStatusTest.php
php artisan molly:start retry-button
php artisan molly:show RUN_ID --verbose
```

The report labels each file `added`, `removed`, `modified`, or `unchanged`. A changed hash proves the file changed, not that the screen looks right. For images, configure a local renderer and Molly will capture a before and after preview under `.molly/previews/`; [Component snapshots](component-snapshots.md) has the settings. Previews are advisory and never affect whether the task passes.

## Two tasks at once

Pest and the Tarpit review already run in parallel inside one run. Two runs cannot share a workspace: the second start reports `WORKSPACE_BUSY` and does nothing. To run tasks side by side, give each its own checkout:

```bash
git worktree add ../app-ready main
composer install --working-dir=../app-ready
php artisan molly:create 'Add GET /ready ...' --workspace=../app-ready --name=ready \
  --test=tests/Feature/ReadyTest.php --file=routes/web.php
php artisan molly:start ready
```

The other checkout needs its own `vendor` directory, because Pest runs there. Each workspace keeps its own `.molly/` directory, receipts, and lock. The tasks still share the application database, so `molly:tasks` lists both.

## Tune Laravel AI

Molly reaches models through Laravel AI, so provider settings live in `config/ai.php` and Molly's policy in `config/molly.php`.

To move Ollama to another local port:

```dotenv
OLLAMA_URL=http://127.0.0.1:11435
```

To give a slow model more time per request, raise `molly.timeout` (180 seconds by default) and the Pest budget `molly.test_timeout` (120). Clear the configuration cache and run doctor after each change.

To ask Jev for advice, enable the gate and add a TypeSafe key. Your application must have `laravel/ai` at a version with the classification API; [Jev](reference/configuration.md#jev) has the exact `composer require` line and the doctor codes:

```dotenv
MOLLY_JEV_ENABLED=true
TYPESAFE_API_KEY=...
```

`molly.jev.confidence_threshold` (0.8) decides when Molly keeps its own guidance instead of Jev's.

## Jev in the loop

With Jev enabled, three commands consult it:

```bash
php artisan molly:advice ready-check
php artisan molly:plan --resume=PLAN_ID
php artisan molly:review-commit --staged
```

Advice asks whether a failed run deserves another attempt and shows the probabilities Jev returned. A plan suggestion names the planning question most worth another look. A commit review asks whether a staged PHP diff has a semantic problem worth fixing. In each case Jev's answer is advisory: it cannot make a failed test pass, widen retries, or approve a change. When its confidence is below the threshold, the report says `low_confidence` and Molly's own guidance stands. [Task advice](task-advice.md#advice-from-jev) shows the fields.

## The web interface with Flux and Livewire

Molly's browser pages are plain Blade views styled with the free Flux components and one Livewire component for run status. They need no build step and no Flux Pro license:

```dotenv
MOLLY_UI_ENABLED=true
```

```bash
php artisan config:clear
php artisan serve
```

Open `http://127.0.0.1:8000/molly`. Creating a task from the form saves it exactly as `molly:create` does. Starting one from the browser queues a job, so a worker must be running; [Web interface](web-interface.md) has the queue rules.

## Molly on its own, or with Bloom

The two setups share every record. [Molly on its own](standalone.md) tours Artisan and the web interface. [Molly with Bloom](bloom.md) explains what Bloom adds and what still runs outside it.

## Not yet: running on an Orb

Molly can save a link between a task and an Amp thread and read Amp's reported connection state, but it cannot send a task to a remote machine or verify that an executor is an Orb. That work is planned and described in [Execution targets](execution-targets.md). Until it ships, every run executes on the machine where you run Artisan.

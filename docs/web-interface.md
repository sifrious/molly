---
layout: default
title: Use the local web interface
---

# Use the local web interface

Create a task in your browser, start an attempt, and read the same evidence available in the CLI. The queue worker continues execution when you close the page.

## Before you start

Complete [getting started](getting-started.md) and resolve failed `molly:doctor` checks. Keep the host application's database connection configured and its normal jobs and failed-jobs migrations installed.

Molly includes free Flux 2 and Livewire 4. The package supplies its CSS. You do not need Flux Pro, a license key, or a Vite build for Molly's pages.

## Enable the interface

Add these settings to the host application's `.env`:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
QUEUE_CONNECTION=database
```

In the host application's `config/queue.php`, set the database connection's `retry_after` to `3700`. Keep the other connection settings. This value must exceed the Molly job's 3600-second timeout so another worker cannot reserve the request while the first worker is still running.

Run the migrations and start a worker:

```bash
php artisan config:clear
php artisan migrate
php artisan queue:work --tries=1 --timeout=3600
```

In a second terminal, start the web server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/molly`. You should see the task list. An empty list is expected in a new installation.

## Create and start a task

Choose **Create task**. Enter a prompt, an optional task nickname, the workspace directory, the required Pest test path, and any other files Molly may change, one path per line. Selecting the test also permits test edits, so you do not need to list the test again. Leave the other-files field empty for a task that only changes the test. You can also provide a GitHub issue URL; the imported issue replaces the prompt.

Saving creates a pending task. Choose **Start task** to queue execution. A queued request does not count as a run until the worker starts the attempt. Use **Refresh task** to see the recorded attempts, then open a run to read its evidence.

The task list shows the latest 100 saved tasks. A task page shows the original prompt, selected files, status, and attempt history. Failed or stopped tasks can be retried within the configured attempt limit. Pending or active tasks can be stopped. Read [task controls](tasks.md) for the exact retry and stop behavior.

Use the task page's nickname form to name or rename a task. The nickname also works in CLI commands such as `php artisan molly:task health-check`. Task page URLs keep the UUID, so renaming leaves existing links valid. See [nickname rules](tasks.md#name-a-task).

## Read the run

The run page starts with Tarpit, Clever, and Pest results. Follow the section links to inspect findings, compare measurements, read test output, and check changed-file hashes. Branch identities and the complete JSON report remain available in expandable details.

Livewire refreshes the run status and current phase when JavaScript is available. **Refresh all evidence** reloads the full saved report. Every core page renders complete HTML, and the create, import, name, start, retry, and stop forms work without JavaScript.

A completed run has passed the required checks. Review the workspace diff and test assertions before committing. A missing or skipped result does not become a passing check. See [verification and complexity evidence](verification.md).

## Multiple SQLite workers

For multiple workers sharing SQLite, the tested configuration requires PHP 8.4 or later. Set these keys on the SQLite connection in the host application's `config/database.php`:

```php
'transaction_mode' => 'IMMEDIATE',
'busy_timeout' => 10000,
```

`busy_timeout` is in milliseconds. With SQLite's default `DEFERRED` transactions, concurrent workers can fail while reserving a job with `database is locked`, before Molly starts the task. Laravel applies `transaction_mode` only on PHP 8.4 or later. Use one SQLite worker on PHP 8.3. Molly does not change the host's database settings.

## Queue connections

The web dispatcher accepts database, Redis, Beanstalkd, and SQS connections. Database, Redis, and Beanstalkd require `retry_after` above 3600 seconds. For SQS, set the queue's visibility timeout above 3600 seconds in AWS. Molly cannot inspect that setting.

Synchronous, deferred, null, and failover connections are rejected. Each queued request gets one job attempt. Molly does not automatically retry a failed task. Duplicate requests cannot execute the same task concurrently; the worker checks task state and attempt limits before starting.

If no run appears, read the worker output and run `php artisan queue:failed` before submitting another request. The [troubleshooting guide](troubleshooting.md) covers queue errors and interrupted runs. CLI execution does not need a queue worker.

## Local access

The interface accepts the `local` and `testing` environments, direct loopback clients, and a `localhost`, `127.0.0.1`, or `[::1]` host. Custom development domains and remote clients are rejected. The same guard checks Livewire requests.

The interface has no login or approval controls. Keep the server bound to loopback on a trusted machine. GitHub Pages will host the documentation only; the local task interface remains part of your Laravel application.

Change the URL prefix through `molly.ui.prefix`. See the [configuration reference](reference/configuration.md). `src/Http/TaskController.php` queues `src/Jobs/StartSavedTask.php`, which calls the same start and retry actions as the CLI.

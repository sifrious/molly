---
layout: default
title: Local web interface
---

# Local web interface

Use the browser UI when you want to create saved tasks and read run evidence without staying in the terminal.

The UI is local-only, disabled by default, and has no login. Do not expose it to the public internet.

## Before you start

Finish [Getting started](getting-started.md) and make sure this passes:

```bash
php artisan molly:doctor
```

## Enable the UI

Add to `.env`:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
QUEUE_CONNECTION=database
```

For the database queue, set its `retry_after` to a value above Molly's 3600-second job timeout. `3700` is the documented example.

Run migrations and start a worker:

```bash
php artisan config:clear
php artisan migrate
php artisan queue:work --tries=1 --timeout=3600
```

In another terminal:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open:

```text
http://127.0.0.1:8000/molly
```

## Create and start a task

Choose **Create task** and provide:

- the request
- an optional nickname
- the workspace
- the required Pest test
- any other files Molly may edit

Saving creates a pending task. Starting it queues execution.

A queued request is not the same as a completed run. The queue worker must pick up the job first.

## Inspect the project graph

Choose **Project graph** and provide the workspace path. Molly rebuilds saved task, test, attempt, and blocker relationships for that directory. The page does not start an agent.

Verification blockers are listed first. The snapshot stops at 40 nodes.

## Read a run

The run page shows the same saved evidence as the CLI:

- Pest results
- Tarpit checks and findings
- Clever measurements
- selected-file hashes
- run status and branch details

You can always read the same run from the terminal:

```bash
php artisan molly:show RUN_ID --verbose
```

## Queue requirements

Supported queue drivers for web and MCP start/retry requests are:

- database
- Redis
- Beanstalkd
- SQS

For database, Redis, and Beanstalkd, reservation time must exceed 3600 seconds. For SQS, set the queue visibility timeout above 3600 seconds in AWS.

Synchronous, deferred, null, and failover queue drivers are rejected for these requests.

If no run appears:

```bash
php artisan queue:failed
```

Then inspect the worker output before submitting another request.

## SQLite with more than one worker

On PHP 8.4 or later, a tested multi-worker SQLite setup uses:

```php
'transaction_mode' => 'IMMEDIATE',
'busy_timeout' => 10000,
```

Use one SQLite worker on PHP 8.3. Molly does not change the host application's database settings for you.

## Local access rules

The UI accepts local or testing environments and loopback access through `localhost`, `127.0.0.1`, or `[::1]`.

Custom development domains, tunnels, and remote clients are rejected by the local access guard.

## Next

- [Manage tasks](tasks.md)
- [Verification](verification.md)
- [Troubleshooting](troubleshooting.md)

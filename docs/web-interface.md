# Web interface

Molly has a small browser interface for reading tasks, runs, plans, and the project graph, and for creating and starting tasks. It is off by default, local-only, and has no login. Do not expose it beyond your machine.

## Enable it

Add to `.env`:

```dotenv
APP_ENV=local
MOLLY_UI_ENABLED=true
```

Clear the configuration cache and serve the application:

```bash
php artisan config:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/molly`. The route prefix is `molly.ui.prefix` in `config/molly.php`.

Molly accepts requests only in the `local` and `testing` environments and only from `localhost`, `127.0.0.1`, or `[::1]`. A custom development domain, a tunnel, or a remote client gets a 403.

## The pages

| Page | What it shows |
| --- | --- |
| `/molly` | The latest 100 tasks with status and created time. |
| `/molly/tasks/{task}` | One task: status, display status, workspace, protected test, nickname form, prompt, selected files, journal links, start, retry, and stop forms, the advice button, and every attempt. |
| `/molly/runs/{run}` | One run: a summary of Pest, Tarpit, and Clever, then the full Tarpit table, measurements before and after, Pest output, changed files, and receipts. |
| `/molly/tasks/create` | The same form as `molly:create`. |
| `/molly/plans` and `/molly/plans/{plan}` | Saved plans, the guided questions, and the Jev suggestion when one was requested. |
| `/molly/graph` | The project graph for a workspace, blockers first. |
| `/molly/guide` | The bundled planning guide and its sources. |

Reading a page is side-effect free. Every page shows the same saved records as the Artisan commands.

## Start a task from the browser

The start, retry, and stop buttons on a task page queue a job instead of running in the request, so the application needs a queue worker:

```dotenv
QUEUE_CONNECTION=database
```

```bash
php artisan migrate
php artisan queue:work --tries=1 --timeout=3600
```

Refresh the task page to see the attempt appear. A queued request is not a completed run; the worker has to pick it up first.

If no run appears, check `php artisan queue:failed` and the worker output before starting again.

### Queue requirements

Web and MCP starts work with the database, Redis, Beanstalkd, and SQS drivers. The reservation time (or the SQS visibility timeout) must be longer than 3600 seconds, Molly's job timeout; `3700` is the documented value for `retry_after`. The sync, deferred, null, and failover drivers are rejected.

With SQLite and more than one worker on PHP 8.4 or later, set `transaction_mode` to `IMMEDIATE` and `busy_timeout` to `10000` on the connection. On PHP 8.3, use one worker. Molly does not change your database settings.

Starting a task from Artisan needs no worker.

## Ask for advice

The "Get next-step advice" button on a task page runs the same logic as `molly:advice`, shows the result, and saves it with the run. When Jev is enabled it shows the provider block too. [Task advice](task-advice.md) explains the fields.

## Next

- [Tasks](tasks.md)
- [Project graph](project-graph.md)
- [Troubleshooting](troubleshooting.md#the-web-interface-returns-404-or-no-run-appears)

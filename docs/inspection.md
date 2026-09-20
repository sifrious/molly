# Tasks, runs, and conversations (MME-5303)

Inspection chain:

**task → run → conversation → files/diff/evidence (via run report) → verification**

## Persistence

- Tasks and runs remain in Molly's database (`molly_tasks`, `molly_runs`).
- Conversations persist under `$MOLLY_HOME/conversations/<uuid>.json` (default `~/.molly/conversations`).
- Ensuring a conversation stamps `report.conversation_id` on the run.

## CLI

```bash
php artisan molly:inspect demo-task --ensure-conversations --json
php artisan molly:inspect --run=<run-uuid> --ensure-conversations --json
php artisan molly:inspect --conversation=<id> --json
php artisan molly:inspect --conversations --json
```

Retry and stop stay on Molly domain actions (`molly:retry`, `molly:stop`).

## Bloom

Nav ids: `molly.tasks`, `molly.conversations` — thin adapters over `molly:inspect`. Runs open as detail sheets from a task (same inspect payload); no separate `molly.runs` nav for v0.1.

# Inspecting tasks, runs, and conversations

Every task links to its runs, every run links to the conversation Molly had with the agent, and the conversation links back. `molly:inspect` follows those links from any starting point.

```bash
php artisan molly:inspect ready-check
php artisan molly:inspect --run=RUN_ID
php artisan molly:inspect --conversation=CONVERSATION_ID
php artisan molly:inspect --conversations
```

The first form shows the task, its runs, and the conversation of the latest run. `--run` starts from one run and includes its loop phase, timing, inputs, outputs, verification, receipts, and stop reason. `--conversation` prints the messages of one conversation. `--conversations` lists them all. Add `--json` for scripts.

## Where things live

Tasks and runs are rows in your application database (`molly_tasks` and `molly_runs`). Conversations are JSON files under `~/.molly/conversations/`, or `$MOLLY_HOME/conversations/` if you set `MOLLY_HOME`. A run created before conversations were persisted has none until you ask for it:

```bash
php artisan molly:inspect ready-check --ensure-conversations
```

That writes the conversation from the run's saved report and stamps `conversation_id` on the run.

## What a conversation holds

The messages are the prompt Molly sent, the phase changes, the verification receipts, and any error, each with a timestamp and a provenance record naming the run it came from. Model responses that were rejected before they were applied are not replayed as messages; the error explains why.

Retrying or stopping stays with `molly:retry` and `molly:stop`. Inspection only reads.

## In Bloom

Bloom's Tasks and Conversations screens are views over the same command. See [Molly with Bloom](bloom.md).

## Next

- [Tasks](tasks.md)
- [Verification](verification.md)

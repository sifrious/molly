# Project graph

The project graph answers questions about your own tasks: why a task is blocked, which test proves it, which files a run touched, and what evidence came out. Molly rebuilds it from saved records in one workspace. Nothing in it comes from a model.

## Build it

```bash
php artisan molly:project:index
```

Indexing reads the saved tasks, runs, receipts, and lifecycle events for the workspace. It does not start an agent. Pass `--workspace=PATH` for another checkout.

## Ask it a question

Start from a task name, a test path, a file, a run ID, or a blocker label:

```bash
php artisan molly:project:query ready-check
```

The result is the neighborhood around that node, two hops by default and at most 40 nodes, in a stable order. `--depth` runs from 0 to 3, `--limit` from 1 to 40, and `--relation` keeps only the named relationships. Add `--json` for scripts.

### Why is this task blocked?

A blocker hangs off the run that failed, so follow the task to its runs and the runs to their blockers:

```bash
php artisan molly:project:query always-fails --depth=2 --relation=produced --relation=blocked_by
```

You get the task, the attempt that failed, a blocker node per verifier that stopped it (`pest blocked completion`, `tarpit blocked completion`, or `parallel_join blocked completion`), and the verifier evidence the run produced.

### What proves this task?

```bash
php artisan molly:project:query ready-check --depth=1 --relation=verified_by
```

The task links to its protected Pest test. Once a person locked a test the agent wrote, an approval node appears as well.

### What did this run change?

```bash
php artisan molly:project:query RUN_ID --depth=1 --relation=changes
```

### What evidence came from this run?

```bash
php artisan molly:project:query RUN_ID --depth=1 --relation=produced
```

The run links to the Pest, Tarpit, and parallel-join evidence it produced. A Tarpit finding stays a finding; Molly does not rewrite it as a vague "needs work" label.

### What is the smallest thing to fix next?

Start from the blocker label and walk back to the tasks:

```bash
php artisan molly:project:query 'pest blocked completion' --depth=2 --relation=blocked_by --relation=produced
```

That lists every attempt Pest blocked and the tasks that made them. Pick the task whose latest attempt has one failing assertion, read its run with `molly:show`, and fix that first.

## Read it in the browser

With the [web interface](web-interface.md) enabled, open `/molly/graph`, enter the workspace path, and choose "Rebuild graph". Blockers are listed first and the page stops at 40 nodes. For anything larger, use the queries above.

## What is in it

`molly:project:index` reads Molly's saved records for one workspace: tasks, their runs, run reports, test locks, and recorded pull requests. It does not read your application's PHP source.

| Relationship | From | To | Comes from |
| --- | --- | --- | --- |
| `runs_in` | task | workspace | the task's workspace |
| `verified_by` | task or run | acceptance test | the task's protected Pest test |
| `approved_by` | acceptance test | approval | a person locking a test the agent wrote |
| `approved_by` | task | approval | a recorded pull request |
| `changes` | task | file | the task's file scope |
| `changes` | run | file | files the run report lists as changed |
| `implements` | task | GitHub issue | a task imported from an issue |
| `produced` | task | run, pull request | attempts and the recorded pull request |
| `produced` | run | verifier evidence | Pest, Tarpit, and parallel-join outcomes |
| `produced` | pull request | commit | the recorded merge SHA |
| `blocked_by` | run | blocker | a required verifier that did not pass, or a changed protected test |

Every node and relationship carries one source record for the workspace. Depth runs from 0 to 3 and the limit from 1 to 40, the same as the Laravel knowledge graph.

### What it does not cover

The project graph has no nodes for your classes, methods, routes, or container bindings, and no relationships between them. A file appears only because a task or run named it. Calls in your code, including dynamic ones such as `app($name)` or `$class::make()`, produce no node and no relationship, resolved or unresolved. The [Laravel knowledge graph](knowledge-graph.md) covers framework symbols, not your application code. Indexing application source is not part of Molly 0.2. That scope was decided on 2026-09-28, and the [acceptance manifest](acceptance/manifest.json) records the check for unresolved dynamic calls, M08.7, as not applicable.

### Moved or deleted files

Each index checks the files that `changes` and `verified_by` relationships point to. When a file is gone, for example after `git mv`, Molly keeps the relationship and the file node so the history stays readable, and marks them:

- the relationship metadata becomes `{"status": "unresolved", "reason": "target_missing", "path": "app/Services/Greeter.php"}`
- the file node metadata becomes `{"exists": false}`

Molly does not guess the new path. Run `molly:project:index` again after restoring the file and the marks disappear.

The graph is stored in `.molly/knowledge.sqlite` next to the Laravel knowledge, in its own namespace. It is disposable; delete the file and index again.

## What it does not do

The graph is a view. Querying it changes nothing, and editing the database does not change a task. It cannot approve, retry, or merge. A visual Bloom view is planned; today the terminal and the local web page are the two ways to read it.

## Next

- [Laravel knowledge](knowledge-graph.md)
- [Web interface](web-interface.md)
- [Verification](verification.md)

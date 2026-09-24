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

Nodes are tasks, acceptance tests, files, runs, verifier evidence, blockers, the workspace, and imported GitHub issues. Relationships are `implements`, `verified_by`, `changes`, `runs_in`, `blocked_by`, `approved_by`, and `produced`. Depth runs from 0 to 3 and the limit from 1 to 40, the same as the Laravel knowledge graph.

The graph is stored in `.molly/knowledge.sqlite` next to the Laravel knowledge, in its own namespace. It is disposable; delete the file and index again.

## What it does not do

The graph is a view. Querying it changes nothing, and editing the database does not change a task. It cannot approve, retry, or merge. A visual Bloom view is planned; today the terminal and the local web page are the two ways to read it.

## Next

- [Laravel knowledge](knowledge-graph.md)
- [Web interface](web-interface.md)
- [Verification](verification.md)

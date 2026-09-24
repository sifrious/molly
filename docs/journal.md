# Journals and decisions

Molly can write what it knows about a task to Markdown files you can read without Artisan, and it can record a decision you made in a file you commit. The database stays the source of truth; the Markdown is a view of it.

## Export a task journal

```bash
php artisan molly:journal ready-check
```

Molly writes `.molly/journal/TASK_UUID.md` with the request, the file scope, the protected test, each attempt's state, Pest counts, Tarpit checks and findings, Clever measurements, the recorded pull request or merge if there is one, and the lifecycle events from `.molly/lifecycle.jsonl`. Run it again after another attempt to refresh the file. Missing evidence is written as missing.

## The project journal and glossary

Every run also refreshes two files for the workspace:

```text
.molly/JOURNAL.md
.molly/GLOSSARY.md
```

The journal lists the saved tasks and their attempts. The glossary has a section Molly maintains and room for your own project terms outside it. If a task page warns that the project journal could not be refreshed:

```bash
php artisan molly:journal ready-check --project
```

A journal write failure never changes a run's result.

## Keep them out of Git

Journals can contain task descriptions and review text, so read them before sharing. Molly writes them with owner-only permissions and adds an ignore rule inside `.molly/.gitignore`. Keep `.molly/` ignored in your repository as well; Molly does not edit your root `.gitignore`.

## Record a decision

```bash
php artisan molly:decide --title="Keep Pest required" \
  --body="Pest remains the completion gate for every task."
```

Molly writes `docs/decisions/YYYY-MM-DD-keep-pest-required.md` in the workspace. Running the same title and body again on the same day leaves the file unchanged. Add `--task=ready-check` to link the decision to a task. Commit the file if the repository should keep it; Molly does not commit for you.

## Next

- [Tasks](tasks.md)
- [Verification](verification.md)

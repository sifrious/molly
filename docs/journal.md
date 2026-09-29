# Journals and decisions

Molly can write what it knows about a task to Markdown files you can read without Artisan, and it can record a decision you made in a file you commit. The database stays the source of truth; the Markdown is a view of it.

## Export a task journal

```bash
php artisan molly:journal ready-check
```

Molly writes `.molly/journal/TASK_UUID.md` with the request, the file scope, the protected test, each attempt's state, Pest counts, Tarpit checks and findings, Clever measurements with their warnings and limitations, the recorded pull request or merge if there is one, and the lifecycle events from `.molly/lifecycle.jsonl`. Run it again after another attempt to refresh the file. Missing evidence is written as missing.

## The project journal and glossary

Every run also refreshes two files for the workspace:

```text
.molly/JOURNAL.md
.molly/GLOSSARY.md
```

The journal lists the saved tasks and their attempts. The glossary has a section Molly maintains and room for your own project terms outside it. To write both files, for example before the first task or after a task page warns that the project journal could not be refreshed:

```bash
php artisan molly:journal --project
php artisan molly:journal --project --workspace=/path/to/app
php artisan molly:journal ready-check --project
```

Without a task, `--project` refreshes the workspace of the current app, or the one named by `--workspace`. With a task, it refreshes that task's workspace and records the result on the task.

`php artisan molly:glossary --json` lists the Molly terms with their links. The source link resolves from the workspace: `vendor/sifrious/molly/src/Journal/JournalRenderer.php` when Molly is installed with Composer, or `src/Journal/JournalRenderer.php` in a Molly checkout. When Molly's source is not under the workspace, the link has kind `package_source` and a `package:sifrious/molly/` prefix instead of a path. Each term's `provenance.source` uses the same path.

In `.molly/GLOSSARY.md`, each Molly term ends with its source. The Markdown link is relative to `.molly`, such as `../vendor/sifrious/molly/src/Journal/JournalRenderer.php`, so it opens from the file. A `package:` source is shown as text, not a link.

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

# Molly with Bloom

Bloom is a macOS workspace and review tool. Molly does not need it. When both are present, Bloom owns the checkout, the branch, the diff, and the pull request screens, and Molly owns the task, the protected test, verification, and the evidence.

This page describes what exists today. Set up Molly first with [Getting started](getting-started.md); Bloom adds screens around records Molly already keeps.

## What Bloom adds

Bloom reads the task contract Molly exports, binds it to the workspace you selected in Bloom, and shows the Molly task there. Its pull request and merge controls wait until Molly has recorded a human approval.

The Molly plugin adds eight screens to Bloom's sidebar. Each screen runs an Artisan command with `--json` in the selected Laravel app and shows the result. When a command fails, the screen shows the exact command line, the working directory, the exit code, and stderr.

| Screen | Commands |
| --- | --- |
| Molly | Project picker from `~/.molly/projects.json`, `molly:project-new`, `molly:project-init`, `molly:graphs-bootstrap`, and the graph manifest at `.molly/graphs/manifest.json` |
| Tasks | `molly:tasks`, `molly:create`, `molly:inspect --task`, `molly:start`, `molly:stop`, `molly:retry`, `molly:approve --approve` |
| Runs | `molly:task` for a task's attempts, `molly:inspect --run`, `molly:receipt`, and `git -C WORKSPACE diff` |
| Conversations | `molly:inspect --conversations` and `molly:inspect --conversation` |
| Graph | `molly:project:query`, with the sources recorded for every node and edge |
| Glossary | `molly:glossary`, read only |
| Molly Settings | `molly:settings` and `molly:settings-set --patch` |
| Worker | `molly:worker status`, `start`, `stop`, `restart`, and `molly:status` |

Records link to each other by the IDs in the JSON: a task lists its runs and conversations, and a run links to its task, conversation, diff, and receipts. The run page shows every Tarpit check with the status Molly recorded, the unresolved findings, and the Clever probes before and after changes side by side. A skipped check keeps its own status and is never shown as passing.

Approve asks for confirmation before it runs `molly:approve TASK --approve`.

### Where commands run

Commands run in the project you pick on the Molly screen. Without a pick, the plugin uses `MOLLY_ARTISAN_HOST`, then the first project in `~/.molly/projects.json` that has an `artisan` file. Set `MOLLY_HOME` to read a different project index.

Bloom starts with the short PATH macOS gives apps opened from the Finder, so the plugin looks for PHP itself: `MOLLY_PHP_BINARY`, then `~/Library/Application Support/Herd/bin/php`, `/opt/homebrew/bin/php`, `/usr/local/bin/php`, and finally `/usr/bin/env php`. If none starts, every screen says PHP was not found and names those paths.

### Limits

- Molly has no command that lists runs across tasks. The Runs screen lists the attempts of one task at a time, or opens a run by ID.
- The diff is `git diff` of the run's workspace as it is now. It is not a snapshot saved with the run.
- `molly:glossary`, `molly:status`, and `molly:worker` are newer than some Molly versions. When the installed Molly does not define one, its screen says so instead of showing data.
- The Glossary screen is read only. Molly has no command that writes glossary terms; add project terms to `.molly/GLOSSARY.md` outside the Molly section.
- Commands run to completion. Start and Retry can take several minutes, and the screen cannot cancel them.
- The plugin cannot switch Bloom's sidebar selection. Links between records open inside the current Molly screen.
- Bloom logs a failed plugin load but not a successful one. The plugin logs its own registration under the subsystem `app.sifrious.molly.surfaces`.

## What still runs outside Bloom

Plans, Laravel knowledge queries, task advice, locking a written test, and exporting the Bloom contract stay in the terminal or the local web interface.

The Bloom adapter that binds Molly's contract has not shipped as a compiled Bloom release. The plugin bundle is not in the Composer package; you build it from a Bloom checkout.

## Export a task contract

Save a task as usual, then export its contract for the workspace Bloom opened:

```bash
php artisan molly:bloom-contract demo-greeting \
  --workspace-id=WORKSPACE_UUID \
  --branch=BRANCH \
  --base-sha=MERGE_BASE_SHA
```

Bloom shows the workspace UUID, the branch, and the merge-base commit for an open workspace. Outside Bloom, the merge-base of a branch cut from `main` is `git merge-base HEAD main`.

Molly prints the versioned contract and writes it to `.molly/bloom-contract.json`. Add `--json` to print only the JSON.

| Error | Meaning |
| --- | --- |
| `TASK_NOT_FOUND` | No saved task has that name or ID. |
| `PROTECTED_TEST_MISSING` | The task has no locked test digest yet. |
| `TEST_PROTECTED` | The task allows test edits, so it cannot be exported. Lock the test first with `molly:lock-test`. |

Molly never creates a worktree. It attaches to the one Bloom already has.

## Run and review

Run the task with Molly:

```bash
php artisan molly:start demo-greeting
php artisan molly:show RUN_ID --verbose
```

Review the diff in Bloom. Molly's receipts remain the record of whether the task passed.

## Approve, then open the pull request

After the checks pass and you have read the change:

```bash
php artisan molly:approve demo-greeting --approve
```

Bloom's pull request and merge controls unlock after that approval. Molly does not open or merge pull requests. When a person does, Molly can record it:

```bash
php artisan molly:pr-opened demo-greeting --url PR_URL --approve
php artisan molly:merged demo-greeting --sha MERGE_SHA --approve
```

## Hand a task to another workspace

`molly:handoff TASK --from UUID --to UUID --approve` saves an envelope for a child Bloom workspace to `.molly/handoffs/HANDOFF_ID.json` in the task's workspace, then prints the envelope and the path. With `--json` it prints only the envelope. Without `--approve` it refuses with `HANDOFF_UNCONFIRMED` and records nothing. The recipient gets the same file scope and the same protected test; it cannot widen either or merge.

## Install the Bloom plugin

The plugin manifest and SwiftUI screens live under `bloom-plugin/`. Plugin 0.2.0 was built and checked against Bloom commit `1599f05f` (plugin API version 1). Build `BloomPluginAPI` from a Bloom checkout, build the bundle, then register it:

```bash
export BLOOM_ROOT=/path/to/bloom
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
cd -
./bloom-plugin/Surfaces/build-bundle.sh
./bin/molly-bloom-plugin-register
```

The register script checks that Bloom is installed, copies `plugin.json` and `Surfaces.bundle` to `~/Library/Application Support/Bloom/Plugins/sifrious.molly/`, and adds `sifrious.molly` to `enabled.json`. It prints each step, the Bloom commit the app reports, and the commit the bundle was built against. Running it again replaces the installed copy and leaves `enabled.json` alone. It exits with an error when Bloom is missing, when `BLOOM_APP` points nowhere, or when the bundle has not been built.

Quit and reopen Bloom after registering. Bloom loads plugins once at launch.

A bundle only loads in a Bloom that exports the same `BloomPluginAPI` symbols it was linked against. To check a build, compare `nm -u bloom-plugin/Surfaces.bundle/Contents/MacOS/MollySurfaces | grep BloomPluginAPI` with `nm -gU` on the Bloom binary.

## Who owns what

| Bloom | Molly |
| --- | --- |
| The worktree and branch | The task and its allowed files |
| The diff and agent session screens | The protected Pest test |
| The pull request and merge controls | Attempts and retries |
| | Pest, Tarpit, and Clever evidence |
| | Completion, approval, and receipts |

## Next

- [Molly on its own](standalone.md)
- [Verification](verification.md)
- [Commands](reference/commands.md)

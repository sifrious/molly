# Molly with Bloom

Bloom is a macOS workspace and review tool. Molly does not need it. When both are present, Bloom owns the checkout, the branch, the diff, and the pull request screens, and Molly owns the task, the protected test, verification, and the evidence.

This page describes what exists today. Set up Molly first with [Getting started](getting-started.md); Bloom adds screens around records Molly already keeps.

## What Bloom adds

Bloom reads the task contract Molly exports, binds it to the workspace you selected in Bloom, and shows the Molly task there. Its pull request and merge controls wait until Molly has recorded a human approval.

Bloom's navigation can also show Molly screens through a plugin: a Molly home page, the task list, conversations, and Molly's settings. Those screens are thin views over the same `molly:inspect` and `molly:settings` data you can read from Artisan.

## What still runs outside Bloom

Bloom does not yet cover every Molly workflow. Today these stay in the terminal or the local web interface:

- Creating and starting tasks (`molly:create`, `molly:start`, `molly:retry`)
- Reading run evidence in full (`molly:show RUN_ID --verbose` or `/molly/runs/RUN_ID`)
- Plans, the project graph, and Laravel knowledge
- Task advice and locking a written test

The Bloom adapter that binds Molly's contract is source in this repository and has not shipped as a compiled Bloom release. The plugin bundle is not in the Composer package; you build it from a Bloom checkout. Treat the steps below as the contract Molly provides, not as a finished desktop experience.

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

`molly:handoff TASK --from UUID --to UUID` prints an envelope a child Bloom workspace can pick up. The recipient gets the same file scope and the same protected test; it cannot widen either or merge.

## Install the Bloom plugin

The plugin manifest and SwiftUI surfaces live under `bloom-plugin/`. With a Bloom checkout that has `BloomPluginAPI`:

```bash
export BLOOM_ROOT=/path/to/bloom
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
cd -
./bloom-plugin/Surfaces/build-bundle.sh
./bin/molly-bloom-plugin-register
```

The last command copies the manifest and bundle into Bloom's Application Support folder and enables the plugin. Bloom's navigation then shows Molly, Tasks, Conversations, and Molly Settings.

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

---
layout: default
title: QuickStart — Molly + Bloom
---

# QuickStart: Molly + Bloom

Bloom is optional. Use this page when you want Bloom's workspace and review UI around a Molly task.

Finish the [standalone QuickStart](quickstart-standalone.md) first. Molly must be installed, configured, and passing `molly:doctor` in the Laravel app before Bloom enters the picture.

## Who owns what

| Bloom owns | Molly owns |
| --- | --- |
| The worktree or workspace | The task contract |
| The selected branch | Allowed files |
| The diff UI | The protected acceptance test |
| The agent session UI | Attempts and bounded retries |
| The pull request workflow | Pest evidence |
| The merge UI | Tarpit review and Clever measurements |
| | Completion state, approval state, and receipts |

Molly never creates a Bloom worktree. It attaches a task to the workspace Bloom already selected.

## Current status

The Bloom side of this integration is not a finished macOS release yet. The Bloom adapter that reads Molly's contract is unpublished work kept in this repository, and it has not been compiled and proven as a shipped Bloom app. Treat this page as the contract Molly provides today, not as a polished desktop experience.

Molly records approvals, pull requests, and merges that people make. It does not open or merge pull requests on its own.

## 1. Save a Molly task

From the Laravel project root, use the demo task or your own:

```bash
php artisan molly:demo
php artisan molly:task demo-greeting
```

The task's required Pest test must be protected, which is the default. Tasks created with `--allow-test-edits` cannot be exported to Bloom.

## 2. Open the project in Bloom

In Bloom, open the existing Laravel project and branch with **Open existing branch…**. Bloom shows three values for that workspace:

| Value | Meaning |
| --- | --- |
| Workspace UUID | Bloom's ID for the selected workspace |
| Branch | The branch Bloom checked out |
| Merge-base SHA | The 40-character commit the branch started from |

Outside Bloom, the merge-base for a branch that started from `main` is:

```bash
git merge-base HEAD main
```

## 3. Export the contract

```bash
php artisan molly:bloom-contract demo-greeting \
  --workspace-id=WORKSPACE_UUID \
  --branch=BRANCH \
  --base-sha=MERGE_BASE_SHA
```

Molly prints the versioned task contract and writes it to:

```text
.molly/bloom-contract.json
```

Add `--json` to print only the JSON.

Common errors:

| Error | Meaning |
| --- | --- |
| `TASK_NOT_FOUND` | No saved task has that name or ID. |
| `PROTECTED_TEST_MISSING` | The task has no approved test digest yet. |
| `TEST_PROTECTED` | The task allows test edits, so it is not exportable. |

## 4. Bind and run

Bloom binds `.molly/bloom-contract.json` to the selected workspace and shows the Molly task. Run the task with Molly as usual:

```bash
php artisan molly:start demo-greeting
php artisan molly:show RUN_ID --verbose
```

Review the diff in Bloom. Molly's receipts remain the source of truth for whether the task passed.

## 5. Approve, then let Bloom handle the pull request

After the required checks pass and you have reviewed the change:

```bash
php artisan molly:approve demo-greeting --approve
```

Bloom's pull request and merge controls wait for that recorded approval. When a person opens or merges the pull request, Molly can record it:

```bash
php artisan molly:pr-opened demo-greeting --url PR_URL --approve
php artisan molly:merged demo-greeting --sha MERGE_SHA --approve
```

## Next

- [Command reference](reference/commands.md)
- [Understand verification](verification.md)
- [Glossary](reference/glossary.md)

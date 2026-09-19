---
layout: default
title: Handoff. Clone Bloom and finish the Molly loop
---

# Handoff. Clone Bloom and finish the Molly loop

Read this page first. Do not wait for Linear. Do not ask for Linear. Do not import Burdgen.

You are taking over Molly's Bloom integration. The previous orb finished Molly-only work on Linux. It could not compile Bloom. It could not fork Spatie's Bloom. Your job is to clone Bloom, apply the unpublished adapter, and make one advertised workflow:

A small Laravel task starts in Bloom, runs in Bloom's existing workspace, permits only bounded edits, protects its Pest test, verifies the result, records evidence, shows the diff, waits for human approval, then Bloom opens the pull request.

## What "combine" means

Molly and Bloom stay two repositories.

- Molly is a Laravel package. `composer require --dev sifrious/molly`. It must not become production runtime.
- Bloom is a native macOS Swift app. It owns worktrees, agent sessions, diffs, pull requests, and merge.

Combine them by contract and UI, not by merging source trees.

Do not copy Bloom into the Molly Composer package. Do not copy Molly PHP into BloomCore except as JSON the Swift adapter already decodes. Do not invent a second worktree manager, a second pull-request UI, or a competing agent-session manager inside Molly.

If Mary later asks for a monorepo, that is a new product choice. It is not this handoff.

## Exact repository state

Recorded 2026-09-19.

### Molly

- Remote: https://github.com/sifrious/molly
- Amp project: `user_01KYQ9PY7RMGMM0Y47X2VCKEDT/molly`
- Previous source thread: [T-01a0b313-e496-75af-9aa0-d068653fa12d](https://ampcode.com/threads/T-01a0b313-e496-75af-9aa0-d068653fa12d)
- Local HEAD when this handoff was written: `f647b1fad272b888c9000fe4a0b9b647ebdd3f79`
- `origin/main` at that moment: `88fde59a008d324a7c373a5c0177a172112cd31c`

Three local commits may still be unpushed. Fetch first. If they are missing on origin, they are:

1. `ae0058a` Show a local project graph in the web UI
2. `249356d` Fail Pest evidence that did not run the required test
3. `f647b1f` Record Git-tracked decisions in docs/decisions

Do not force-push. Do not push unless Mary asks in your thread.

### Bloom

- Canonical remote: https://github.com/spatie/bloom.git (MIT, Spatie)
- Inspected origin/main: `e03dd1387af7f3f4ae3b7731b8d4fa0843dd2372`
- Needs macOS 26 and Xcode 26 / Swift 6.2
- Viewer permission on Spatie was READ
- `gh repo fork spatie/bloom` failed HTTP 403. Token OAuth scopes were empty. There is no `sifrious/bloom`
- Do not invent a fork. Do not push to Spatie

The unpublished adapter lived in `/home/user/workspace/repos/bloom` and was never committed there. A copy is in this repository under [docs/handoffs/bloom-adapter](bloom-adapter/).

## First actions

1. Fetch Molly. Confirm whether `f647b1f` is on origin. If not, it is local-only from the previous orb.
2. Clone Bloom onto a Mac runner:

   ```bash
   git clone https://github.com/spatie/bloom.git
   cd bloom
   git checkout e03dd1387af7f3f4ae3b7731b8d4fa0843dd2372
   ```

3. Copy the adapter files from Molly's `docs/handoffs/bloom-adapter/` onto that checkout. Keep Spatie copyright. Keep Bloom's own layout.
4. Apply `docs/handoffs/bloom-adapter/bloom-ui.patch` from the Bloom repo root.
5. Run BloomCore tests on macOS. The Linux orb could not run `swift test`.
6. Keep Molly and Bloom as separate git remotes.

## How to apply the snapshot

From the Bloom checkout:

```bash
MOLLY=/path/to/molly
cp "$MOLLY"/docs/handoffs/bloom-adapter/Sources/BloomCore/Molly/*.swift Sources/BloomCore/Molly/
mkdir -p Sources/BloomCore/Molly
cp "$MOLLY"/docs/handoffs/bloom-adapter/Sources/Bloom/Views/Inspector/MollyTaskView.swift Sources/Bloom/Views/Inspector/
cp "$MOLLY"/docs/handoffs/bloom-adapter/Tests/BloomCoreTests/MollyAdapterTests.swift Tests/BloomCoreTests/
cp "$MOLLY"/docs/handoffs/bloom-adapter/Tests/BloomCoreTests/MollyContractFixtures.swift Tests/BloomCoreTests/
git apply "$MOLLY"/docs/handoffs/bloom-adapter/bloom-ui.patch
```

Create `Sources/BloomCore/Molly/` before the copy if it does not exist.

Snapshot contents:

- `Sources/BloomCore/Molly/` ten Swift files
- `Sources/Bloom/Views/Inspector/MollyTaskView.swift`
- `Tests/BloomCoreTests/MollyAdapterTests.swift`
- `Tests/BloomCoreTests/MollyContractFixtures.swift`
- `bloom-ui.patch` for Inspector tab, PullRequestBar, WorkspaceModel, InspectorView
- `BLOOM_HEAD` must equal `e03dd1387af7f3f4ae3b7731b8d4fa0843dd2372`
- `NOTICE` records Spatie's MIT copyright

If origin/main has moved, rebase the adapter onto current Bloom. Do not assume the snapshot still applies cleanly.

## Ownership boundary

Owner | Responsibility
--- | ---
Bloom | Projects, branches, worktrees, workspace lifecycle, agent process, transcript, diff, inline review, pull request, checks, merge, parent/child workspaces
Molly | Small-task contract, allowed files, protected Pest test, attempts, verifier policy, completion, evidence, journal, Laravel guidance
Laravel AI | Provider abstractions when available. Do not hard-couple to unreleased names
TypeSafeAI | Optional typed judgment. Never override a deterministic gate
Tarpit | Seven complexity checks. Show all seven. Never skip-as-pass
Clever | Separate measurements. Never one score
Pest | Hard behavioral gate
Orbs | Capability-checked execution targets. An Amp thread is not Orb identity

Bloom must own the visible loop. Molly's CLI stays headless: contract, diagnostics, automation.

## Contract files Bloom already reads

Molly writes these in the existing workspace. Bloom must not create another checkout to inspect them.

Path | Writer | Reader
--- | --- | ---
`.molly/bloom-contract.json` | `molly:bloom-contract` / `ExportBloomContract` | `MollyWorktreeFiles.loadContract`
`.molly/lifecycle.jsonl` | `RecordLifecycleEvent` | `MollyLifecycleLog`
`.molly/receipts/{run-id}/*.json` | `RecordVerificationReceipts` | inspector evidence
`.molly/knowledge.sqlite` | project and Laravel graphs | Molly only, unless you add a Bloom view later

Schema names:

- `molly.task_contract.v1`
- `molly.lifecycle_event.v1`
- `molly.verification_outcome.v1`

Duplicate `event_id` values are ignored. Unknown newer event types stay inspectable and do not move display status.

## What the unpublished Bloom adapter already does

BloomCore, not SwiftUI, owns the decisions.

- `MollyTaskContract.decode` reads Molly's PHP JSON
- `MollyWorkspaceBinding.bind` refuses a second worktree, a different path, a different branch, a different Bloom workspace id, a different merge-base SHA, and any non-local execution target (`ORB_UNVERIFIED`)
- `MollyWorktreeFiles.importContract` writes the JSON onto the selected worktree
- `MollyLifecycleLog` derives display status. Last known event wins. `TestLocked` maps to pending
- `MollyPullRequestGate` refuses Create until recorded approval. Merge is a second human action after the pull request exists
- `MollyApproval.record` appends `approval_resolved` to `.molly/lifecycle.jsonl`. It does not call GitHub
- Inspector last tab is `Molly`, after Checks, so Checks does not move
- `MollyTaskView` is thin. `WorkspaceModel.refreshMolly` / `approveMolly` re-read the worktree

Tests that must keep passing on macOS:

- `MollyAdapterTests`
- `MollyContractFixtures`
- Inspector tab tests that Molly is last and falls back to Changes

## Required user path

1. User starts from a typed task or a GitHub issue in Bloom.
2. Bloom creates or selects the branch and worktree and finishes setup.
3. User approves Molly's contract: outcome, allowed files, protected Pest test, verifiers, attempt budget, local target.
4. Molly runs in that exact worktree. No second worktree.
5. Bloom shows task state, attempt, transcript, allowed/protected files, verifier outcomes, retry diagnostics, journal references.
6. Agent edits appear in Bloom's existing diff inspector.
7. After required gates pass, Bloom shows human approval.
8. Only after approval may Bloom open or update the PR.
9. Merge stays an explicit human action.
10. Archiving uses Bloom's normal cleanup.

One writing run per Bloom workspace. Parallel tasks need separate Bloom workspaces.

## Molly commands the Bloom loop uses

```bash
php artisan molly:create
php artisan molly:lock-test TASK --approve --file=app/Example.php
php artisan molly:bloom-contract TASK --workspace-id UUID --branch BRANCH --base SHA
php artisan molly:start TASK
php artisan molly:retry TASK
php artisan molly:approve TASK --approve
php artisan molly:pr-body TASK
php artisan molly:pr-opened TASK --url URL --approve
php artisan molly:merged TASK --sha SHA --approve
php artisan molly:handoff TASK --from UUID --to UUID
```

`molly:pr-opened` and `molly:merged` record. They do not open or merge.

MCP `lock_test`, `approve`, `pr_opened`, and `merged` require `approve=true`.

## What Molly already shipped. Do not redo

- WP-01 versioned contracts. Duplicate event IDs ignored. Unknown events inspectable. PHP tests pin task-contract and Pest-failure JSON to `MollyContractFixtures.swift`
- WP-03 protected Pest default. `--allow-test-edits` is explicit and weaker. `molly:lock-test --approve` freezes digest
- WP-04 Landlock plus namespaces. `molly.sandbox.allow_unsafe` is local-only
- WP-05 `failure_action`. Retry restores `.molly/baselines/{taskId}.json`. Immutable receipts. False-green: JUnit must name the required test file or matching class
- WP-02 headless `molly:bloom-contract` writes `.molly/bloom-contract.json`. `workspace_prepared` records `created_worktree: false`
- WP-06 import digest, `molly:comment`, printed PR body, recorded PR/merge
- WP-07 local default. Unverified Orb throws `ORB_UNVERIFIED`
- WP-08 `molly:handoff` cannot widen scope, edit the protected test, or merge
- WP-09 advisory classification cannot upgrade failed Pest
- WP-10 project graph plus local `/molly/graph`. Laravel knowledge for queues, routing, testing, validation, container, Eloquent, events. NativePHP and tarpit separate. `molly:decide` writes `docs/decisions/`
- WP-11 optional local previews under `.molly/previews`. Advisory
- Headless approval: `molly:approve TASK --approve`

House style in Molly:

- Feature tests use temp dirs and `writeProtectedTest()`
- Journal `escape()` backslash-escapes markdown punctuation
- Tests set `molly.sandbox.allow_unsafe` true except isolation tests
- Isolation tests skip without Landlock
- NativePHP CLI uses `--nativephp-version`
- Knowledge namespaces: `laravel`, `nativephp`, `tarpit`, `project`
- Unslop on docs, UI copy, and commit subjects
- Commit subject names the behavior, such as `Show skipped complexity checks`
- Co-authored-by Mary Perry `<mmebyte@gmail.com>`
- Amp-Thread-ID trailer on commits
- Do not push unless Mary asks in the current thread

Package checks:

```bash
vendor/bin/pest
vendor/bin/pint --format agent
```

Tests use in-memory SQLite. Do not start a database server.

## Proof the next agent must produce

Do not call MOL-WP-02 done because Swift types exist.

On a Mac with Bloom Dev.app:

1. Open a Laravel project that has Molly installed.
2. Let Bloom create one workspace. Note path, branch, workspace id, merge-base SHA.
3. Author or lock a Pest test. `molly:lock-test --approve`.
4. Export `molly:bloom-contract` into that same path. Confirm `.molly/bloom-contract.json` exists. Confirm no second worktree.
5. Bind in Bloom. The Molly inspector tab appears. Create pull request stays disabled.
6. Fail the first implementation attempt. Diff and history stay in the same Bloom workspace.
7. Retry with prior diagnostics. Required gates pass. Pest cannot pass from Tarpit, Clever, or TypeSafe.
8. Human presses Approve on the Molly tab. Lifecycle records `approval_resolved`.
9. Bloom's existing strip may now open the PR. Molly still does not open it.
10. Merge remains a second human action.

Capture that proof. A Linux orb screenshot is not the proof.

## Hard refusals

- Do not create a second worktree for Molly
- Do not open or merge a GitHub pull request from Molly
- Do not treat an Amp thread as Orb identity
- Do not weaken Pest. `false_green` stays a hard failure
- Do not make `--allow-test-edits` the default
- Do not import Burdgen or any private package
- Do not copy Bloom application code into the Composer package
- Do not claim Packagist, a tagged `v0.1.0-alpha.1`, GitHub Pages, or verified Orb dispatch
- Do not push Molly or a Bloom fork unless Mary asks in your thread
- Do not wait on Linear IDs. They are historical only

## Writable Bloom checkout

Spatie remains read-only for this account.

Practical options, in order:

1. Clone Spatie locally on a Mac runner and keep adapter commits unpushed until Mary has a writable remote.
2. If Mary can create `sifrious/bloom` from a personal GitHub login, fork there and open a PR back to Spatie or keep a long-lived fork.
3. Do not use `gh repo fork` from the previous orb token. It returned 403.

Until a writable remote exists, keep Bloom commits local and copy any new Swift into `docs/handoffs/bloom-adapter/` so a later orb is not stuck on Spatie origin.

## Suggested split if you use parallel orbs

Work that can run on Linux, in Molly:

- Keep the PHP contract tests matching the Swift fixtures in `MollyContractFixtures.swift`
- Headless Bloom-contract export, approval, lock-test, PR body
- Docs for the Bloom path once the Mac proof exists

Work that needs macOS:

- Apply the adapter
- `swift test` for MollyAdapterTests and InspectorTabTests
- Bloom Dev.app end-to-end proof
- SwiftUI polish of `MollyTaskView`

Do not start Orb dispatch, Packagist, or a docs site in this handoff.

## Files to read first

Molly:

- [src/Actions/ExportBloomContract.php](../../src/Actions/ExportBloomContract.php)
- [src/Contracts/TaskContract.php](../../src/Contracts/TaskContract.php)
- [src/Contracts/LifecycleLog.php](../../src/Contracts/LifecycleLog.php)
- [src/Actions/LockProtectedTest.php](../../src/Actions/LockProtectedTest.php)
- [src/Actions/ApproveTask.php](../../src/Actions/ApproveTask.php)
- [docs/work-packages.md](../work-packages.md)
- [AGENTS.md](../../AGENTS.md)

Bloom snapshot:

- [bloom-adapter/Sources/BloomCore/Molly/MollyTaskContract.swift](bloom-adapter/Sources/BloomCore/Molly/MollyTaskContract.swift)
- [bloom-adapter/Sources/BloomCore/Molly/MollyWorkspaceBinding.swift](bloom-adapter/Sources/BloomCore/Molly/MollyWorkspaceBinding.swift)
- [bloom-adapter/Sources/BloomCore/Molly/MollyPullRequestGate.swift](bloom-adapter/Sources/BloomCore/Molly/MollyPullRequestGate.swift)
- [bloom-adapter/bloom-ui.patch](bloom-adapter/bloom-ui.patch)

Bloom upstream:

- https://github.com/spatie/bloom/blob/main/docs/ARCHITECTURE.md
- https://github.com/spatie/bloom/blob/main/AGENTS.md
- https://github.com/spatie/bloom/blob/main/CLAUDE.md

## Still unfinished after a successful Bloom bind

- Verified Orb dispatch
- Packagist and tagged `v0.1.0-alpha.1`
- GitHub Pages / public docs site
- Visual Bloom project graph
- Rendered component previews in Bloom
- Broader Laravel knowledge beyond the current namespaces

Those are later work packages. Finish the Bloom loop proof first.

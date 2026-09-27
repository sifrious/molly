# Human prelaunch cards H01 to H13

Status: draft. These cards stay unusable until machine acceptance for MME-5885 passes on one candidate. Only Mary, or the human reviewer she names, records an outcome here.

Each card maps to the machine criterion with the same number in [manifest.json](manifest.json). Every card uses the same header.

- **Candidate:** the commit, version, and zip sha256 from `candidate.json`.
- **Environment:** the Mac model and macOS version, the PHP, Laravel, Ollama, and model versions, and the Bloom Dev build commit.
- **Safe reset:** where to throw away state. Every card works in a disposable Laravel app under a scratch folder. Never point a card at a real project, a real database, or a nearly full disk.
- **Intervention record:** any step you had to fix, repeat, or explain.
- **Outcome:** PASS, FAIL, or BLOCKED, with a note.

Machine edge cases that are unsafe to replay by hand are marked "inspect evidence". For those, open the named evidence folder and confirm that the machine record shows the expected result.

## H01. Instructions and candidate identity (M01)

Starting state: nothing installed. You have the shipped README, the two QuickStarts, and `candidate.json`.

1. Read the README and the QuickStart for each path. Check that every prerequisite is named.
2. Compare the version and install line in the docs with `candidate.json`.
3. Run `php artisan list molly` in a fresh install and compare it with docs/reference/commands.md.
4. Check that docs/compatibility.md claims only platforms that appear in the evidence matrix.

Expected: the docs install this candidate and no older tag, every documented command exists, and no platform is claimed without evidence.

## H02. Fresh installation of both paths (M02)

Starting state: a new Laravel app, and Bloom Dev without the Molly plugin.

1. Follow the standalone QuickStart. Time it and count the manual steps.
2. Run the setup again and check that `.env` and the task list are unchanged.
3. Install the Bloom plugin with the documented command, then launch Bloom Dev.
4. Inspect evidence for the interrupted install, missing extension, dependency conflict, offline, unwritable, and full-disk cases in `evidence-rc*/M02.*`.

Expected: both paths install within the M01 budgets, reruns keep data, and no failure leaves a "ready" marker.

## H03. Runtime control (M03)

1. Run `php artisan molly:worker start`, then `status`, then `start` again, then `stop`.
2. Run `php artisan molly:status --json > status.json` and open the file.
3. Replay one safe failure: `MOLLY_WORKER_PHP_BINARY=/nonexistent php artisan molly:worker start`.
4. Inspect evidence for stale pid, Unicode paths, redirected output, and orphan checks.

Expected: the second start is refused, stop leaves no `queue:work` process, the JSON parses, and the missing binary is named in the error.

## H04. Molly inside Bloom Dev (M04)

1. Launch Bloom Dev and open Molly from the sidebar.
2. Add an existing project, then create a task from plain English.
3. Follow the task to its run, conversation, diff, and receipts.
4. Create a task with the CLI and confirm that it appears in Bloom after a refresh.
5. Quit and relaunch Bloom. Confirm that the same state appears.
6. Check that the settings shown match `effective_config` in `php artisan molly:show RUN --json`.

Expected: every screen shows real data or a named error, and the IDs match the CLI.

## H05. Standalone prompt without Bloom (M05)

Starting state: Bloom is quit.

1. Paste the shipped standalone prompt into a fresh Claude session.
2. Watch it install, inspect the environment, add a project, submit the story, stop and resume, and export a handoff.
3. Try `php artisan molly:approve TASK` without `--approve`.

Expected: the session finishes using shipped instructions only, and approval without `--approve` is refused.

## H06. The plain-English build (M06)

1. Run `php artisan molly:story "<canonical story>" --test=tests/Feature/HelloCounterTest.php`.
2. Read the saved acceptance criteria. Compare them with the story, including invalid login and direct endpoint access.
3. Run the authoring task, then `molly:lock-test --approve`. Read the RED baseline classification.
4. Run the implementation. Read the diff, the receipts, and the GREEN result.
5. Log in, count, log out, and try the counter as a guest in a browser.
6. Repeat with the held-out variation.

Expected: RED is `missing_behavior`, GREEN comes from Molly's run, the locked test digest is unchanged, and the variation also passes.

## H07. Real model and Jev (M07)

1. Open a receipt and read `context.model_identity`. Compare it with `ollama show MODEL`.
2. With Jev off, run `php artisan molly:advice TASK` and confirm that nothing was classified.
3. With Jev on and a TypeSafe key set, run advice, plan suggestion, and commit review.
4. Inspect evidence for outage, low confidence, malformed answers, and a deterministic failure with a favourable Jev answer.

Expected: the digest matches, Jev off is silent, Jev on gives real answers, and a failed Pest run stays failed.

## H08. Knowledge and identity (M08)

1. Run `php artisan molly:project:query` for a class. Open the source file named in the result and check that the line exists.
2. Read `php artisan molly:glossary --json` and follow two links.
3. Inspect evidence for cache reuse, wrong-version rejection, stale revision, symlink boundary, and concurrent locking.

Expected: every edge points at real source, and unresolved edges say so.

## H09. Recovery and authority (M09)

1. Stop a running task with `php artisan molly:stop TASK`, then retry it.
2. Put an unrelated edit in the app before a run, and confirm that the edit survives.
3. Inspect evidence for crash recovery, duplicate delivery, lease expiry, repair budget exhaustion, and secret redaction.

Expected: the unrelated edit is untouched, the repair budget stops at three per distinct failure, and no secret appears in a report.

## H10. UI quality (M10)

1. Use the Molly web UI and the Bloom screens with the keyboard only.
2. Zoom to 200 percent and check for clipped text.
3. Trigger an empty state and an error state on each screen.
4. Read the CI matrix run for the candidate commit.

Expected: controls have labels, focus is visible, and errors say what to do next. Automated accessibility checks cover only the scope declared in M10.6.

## H11. Negative control replay (M11)

1. In a disposable copy, break the authorization check in the generated app.
2. Run the independent suite and confirm that the security assertion fails.
3. Restore the check and confirm that the suite passes.
4. Run the gate against an evidence folder with one file removed.

Expected: the suite fails for the intended reason, and the gate rejects the folder with `FILE_MISSING`.

## H12. Fresh Claude handoff (M12)

1. Give a fresh Claude session only the shipped Bloom prompt, the candidate zip, and the prerequisites.
2. Watch without helping. Record every place where it asks for something the docs did not supply.
3. Repeat with the standalone prompt.

Expected: both sessions finish, and any help you gave is written in the intervention record.

## H13. Hardware preflight (M13)

1. Run `php artisan molly:preflight`. Compare the memory, disk, and Ollama facts with About This Mac, Disk Utility, and `ollama list`.
2. Read the recommendation, and the reason it gives.
3. Inspect evidence for the simulated no-fit, Intel, Rosetta, and low-disk cases. Confirm that no download started.
4. Confirm that Bloom shows the same decision for the same snapshot hash.

Expected: facts match the Mac, unknowns are named, and no download happens without your approval.

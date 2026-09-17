---
layout: default
title: Glossary
---

# Glossary

Molly uses these terms in commands, reports, and the web interface. A task, run, and execution branch describe different levels of work.

| Term | Meaning | Implementation |
| --- | --- | --- |
| Host application | The Laravel application where Molly is installed and Artisan runs. The host stores task history and evidence, even when another checkout is the workspace. | `src/MollyServiceProvider.php`, `src/Actions/RunTask.php` |
| Workspace | The checkout containing the selected files and workspace locks. The workspace needs its own Pest installation. | `src/Workspace.php` |
| File scope | The explicit list of workspace-relative files the writer may propose changing. Also called the file allowlist. The scope does not sandbox code executed by Pest. | `src/Workspace.php`, `src/Actions/GenerateChanges.php` |
| Task | A saved prompt, selected files, required test path, optional source context, and lifecycle state. Task states are `pending`, `running`, `completed`, `failed`, and `stopped`. | `src/Models/Task.php`, `src/Actions/CreateTask.php` |
| Run | One persisted execution attempt, with `running`, `completed`, `failed`, or `stopped` status and a report. A saved task can have several runs. A one-off run has no linked task. | `src/Models/Run.php`, `src/Actions/RunTask.php` |
| Attempt | A run associated with a saved task. The configured attempt limit includes the first run. Retrying creates another run and retains earlier evidence. | `src/Actions/StartTask.php`, `src/Actions/RetryTask.php` |
| Previous-attempt diagnostics | Selected, size-limited test errors and review findings supplied to a retry writer as `previous_attempt`. The diagnostics do not include the complete previous report. | `src/Actions/StartTask.php`, `src/Actions/GenerateChanges.php` |
| Queued execution request | A task ID and start-or-retry choice waiting for a host queue worker. A queued request is not a run until execution begins. | `src/Jobs/StartSavedTask.php`, `src/Http/TaskController.php` |
| Execution branch | One local verification or review process, with its own status, result file, and failure metadata. A branch passing does not establish run completion. | `src/Actions/EvaluateChanges.php`, `src/Console/MollyCheckCommand.php` |
| Branch attempt ID | Identifier shared by the verification and review branches in one parallel evaluation. The identifier binds branch results to that evaluation; the identifier is separate from the saved task and run IDs. | `src/Actions/EvaluateChanges.php` |
| Verification | Pest execution plus the JUnit evidence required to establish that the selected test file passed. A model's statement about tests is not verification. | `src/Actions/VerifyChanges.php` |
| Tarpit review | The model's seven checks for complexity in supplied before-and-after files. The review does not inspect the whole repository or run tests. | `src/Agents/TarpitReviewer.php`, `src/Actions/ReviewChanges.php` |
| Essential complexity | Complexity needed by the current requirement. | `src/Agents/TarpitReviewer.php` |
| Pragmatic complexity | Complexity justified by a documented tradeoff. | `src/Agents/TarpitReviewer.php` |
| Accidental complexity | Complexity that can be removed while preserving required behavior. An unresolved accidental finding can block completion. | `src/Agents/TarpitReviewer.php`, `src/Actions/ReviewChanges.php` |
| Finding | A review result naming the check, file, line, problem, recommendation, classification, and warning or blocking severity. | `src/Actions/ReviewChanges.php` |
| Clever measurement | A probe result containing metrics, scope, limitations, and status. Measurements remain separate from the Tarpit decision and are never combined into a complexity score. | `src/Actions/MeasureComplexity.php`, `src/Complexity/Probes/ProbeResult.php` |
| Probe | One executable measurement, such as owned diff or hotspots. A probe can return `ok`, `skipped`, or `error`. | `src/Complexity/Probes/Probe.php`, `src/Complexity/Probes/ProbeStatus.php` |
| Owned diff | Counts of current code, comment, and blank lines and files in configured paths. The name does not mean a Git patch. | `src/Complexity/Probes/OwnedDiffProbe.php` |
| Welded call site | A literal constructor or static call matched by the probe's text patterns. A match is a candidate for review, not proof of an unnecessary dependency. | `src/Complexity/Probes/WeldedCallSitesProbe.php`, `src/Complexity/Support/WeldScanner.php` |
| Lonely file | A PHP file with one recorded Git author. The listed files must also meet the configured current-size minimum. | `src/Complexity/Probes/LonelyFilesProbe.php` |
| Churn | Number of commits that touched a file path in the selected history window. Churn does not count changed lines. | `src/Complexity/Probes/HotspotsProbe.php` |
| Hotspot | A frequently changed PHP file presented with its churn and current code lines as separate measurements. | `src/Complexity/Probes/HotspotsProbe.php` |
| Evidence | Saved test output, JUnit results, review findings, content hashes, measurement reports, and branch results used to inspect an execution. The host stores supporting files under `storage/molly/{run-id}`. | `src/Actions/RunTask.php`, `src/Actions/VerifyChanges.php` |
| Workspace lease | A token in `.molly/checks.lease` that identifies the current owning run. Check children validate the token while holding a shared lock. A delayed child from an earlier run cannot begin its check with an expired lease. | `src/Workspace.php` |
| Stop request | A persisted instruction to stop a saved task at an execution boundary. A request does not establish that the process has stopped. | `src/Actions/StopTask.php`, `src/Actions/StartTask.php` |
| Interrupted run | A run whose original process exited without recording a final status. A saved task can settle the run through stop once task, workspace, and active-check locks are free. | `src/Actions/StopTask.php` |

Read the [task guide](../tasks.md) for lifecycle behavior and the [verification guide](../verification.md) for completion rules.

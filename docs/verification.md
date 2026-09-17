---
layout: default
title: Tests, Tarpit review, and complexity
---

# Tests, Tarpit review, and complexity

Molly requires test evidence and a complexity review before a run can complete. The report presents both alongside separate Clever measurements. Review the evidence and the resulting diff before accepting a change.

## What completion means

A completed run has changed selected files, passed the required Pest test file, received a complete Tarpit review without blocking findings, and produced usable Clever reports before and after the edit. The selected files must still match the contents reviewed by the model.

By default, Pest and Tarpit review run concurrently in separate local processes. Both branches must return passing evidence. A finished process, a model's claim that tests pass, or a missing report does not satisfy the completion rules.

Disabled measurements, an empty configured probe list, or a failed scan prevents completion. A skipped Clever probe stays visibly skipped. A skip alone does not block completion, but the skipped measurement supplies no evidence about the code.

## Read a saved report

```bash
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
```

Replace `RUN_ID` with the ID from a completed or failed execution. Reading a report does not run the model again. The web [run page](web-interface.md) presents the same saved evidence.

The host application's `molly_runs` table stores the report. Files under `storage/molly/{run-id}` hold supporting test and measurement evidence. Reports include selected paths, content hashes, test output, review findings, Clever results, and branch metadata when available. `src/Console/RunReport.php` renders the terminal report.

## Pest verification

Molly runs the selected workspace's `vendor/bin/pest` against the single file named by `--test`. Molly does not run the application's complete test suite. Include the selected test in the allowed file list so the writer can add or update the required assertions.

`src/Actions/VerifyChanges.php` records the process output and a unique JUnit report. Verification requires at least one executed test. Failures, errors, skipped or incomplete tests, risky tests, warnings, timeouts, and missing or invalid JUnit evidence prevent completion. An empty test file cannot pass verification.

The Pest process removes inherited variables whose names appear in the host environment file. The workspace can then load its own PHPUnit and environment settings. Review the workspace's test configuration before running Molly, as with any local test command.

## The seven Tarpit checks

The reviewer receives the task and saved before-and-after contents of the supplied files. The reviewer cannot explore the repository or run tests.

| Check | Question |
| --- | --- |
| A. Derivable stored data | Can a persisted total, flag, count, or duplicate value be computed without synchronization? |
| B. Impure decisions | Does a domain decision mix I/O, time, randomness, or shared mutation with its rules? Would explicit inputs and returned values help? |
| C. Decisions in transport code | Does CLI, HTTP, or rendering code contain application policy that belongs in an application action? |
| D. Hidden ordering | Does a method depend on an earlier setup call or readiness flag without making that dependency explicit? |
| E. Speculative generality | Does unused code or an abstraction lack a current requirement? Does an existing boundary solve a real dependency problem? |
| F. Volume | Would simplifying a long function, large file, deep nesting, or repeated block improve readability? |
| G. Leaky caches and indexes | Does a cache or derived index alter required behavior or spread dependencies across unrelated code? What verification is missing? |

Check F directs the model to inspect functions over 40 lines, files over 400 lines, nesting deeper than three levels, and duplicated blocks over five lines. These are review prompts, not automatic failure thresholds.

Each check must return a `clean` or `findings` status with specific evidence. `clean` means the reviewer found no issue in the supplied files. The status does not establish whole-repository safety.

Findings include a check code, supplied file path, valid line number in the edited file, problem, and recommendation. Molly validates that the findings match the per-check statuses. Incomplete or inconsistent output fails with `REVIEW_INVALID`.

Each finding also classifies the complexity:

| Classification | Meaning |
| --- | --- |
| `essential` | The current requirement needs the complexity. |
| `pragmatic` | A documented tradeoff justifies the complexity. |
| `accidental` | Removing the complexity preserves required behavior. |

Severity is either `warning` or `blocking`. Only accidental complexity may be blocking. Any blocking finding prevents completion. The instructions live in `src/Agents/TarpitReviewer.php`; validation and the completion decision live in `src/Actions/ReviewChanges.php`.

## Clever measurements

Molly includes the commands adapted from [Clever](https://clever.mary.win/package). No separate Clever installation is required.

```bash
php artisan clever:scan
php artisan clever:scan --json
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

`clever:scan` runs the configured probes and writes a full report. Each individual command runs its named probe and replaces that probe's section in the standalone report. Task runs measure the selected workspace before and after editing and retain separate report files.

The measurements describe different properties. Molly does not combine the numbers into a score. Fewer lines or calls alone do not establish a better design, and uncommitted edits do not add Git history.

### Owned diff

`clever:owned-diff` counts code, comment, and blank lines, with file totals and breakdowns by path and extension. Despite the name, the command measures current files rather than a Git patch.

The default directories are `app`, `bootstrap`, `config`, `database`, `routes`, and `resources/js`. The defaults omit tests and `resources/views`. Compare results using the same configured paths. A comparison with a fresh Laravel application can help estimate code added beyond the application skeleton.

The classifier uses text patterns, so comment markers inside strings or heredocs can affect counts. PHP attributes count as comments to match the intended `cloc` comparison. Counts can still differ from `cloc`. Missing, unreadable, and binary files appear in warnings or skip reasons. The implementation is `src/Complexity/Probes/OwnedDiffProbe.php`.

### Welded call sites

`clever:welds` counts literal constructor calls and static calls in configured PHP paths. Laravel facade calls remain in the static-call total and also have a separate count. The default scope is `app`.

The matching patterns count text in comments and strings. Constructor counts omit `new static`, variable class names, and fully qualified names with a leading backslash. Eloquent static calls remain candidates unless the scanner recognizes the class as a facade. A reported call is a place to inspect, not a requirement to introduce an interface. The implementation is `src/Complexity/Probes/WeldedCallSitesProbe.php`.

### Lonely files

`clever:lonely-files` lists PHP files with exactly one recorded author, ordered by commits that touched the file. The default minimum is 30 current code lines, and the default list contains up to 10 files. The probe uses available PHP history across the repository, independently of the owned-path list.

Author names use Git's exact `%an` value. Different spellings count as different authors. One author can indicate a new file or a short history; the count does not establish a maintenance problem. Missing, unreadable, binary, and undersized files are filtered from the listed results. The implementation is `src/Complexity/Probes/LonelyFilesProbe.php`.

### Hotspots

`clever:hotspots` lists commit counts beside current code lines for PHP files in the configured owned paths. The defaults cover the last 24 months and list up to 20 files.

Churn counts commits that touched a path, not changed lines. The probe does not follow files across renames or calculate cyclomatic complexity. Current line counts describe the working tree; commit counts describe history, including work before a file became smaller. The implementation is `src/Complexity/Probes/HotspotsProbe.php`.

### Scope and skipped probes

Both Git-dependent probes exclude merge commits. Missing Git, a non-Git directory, or a checkout without commits causes a skip. Shallow clones produce a warning because earlier commits and authors may be absent. A Git log failure after successful repository checks produces an error.

Read each result's `caveats`, `notes`, `warnings`, and `skip_reason`. Verbose run reports include hand-verification commands and explain where those commands differ from the probes. For example, the lonely-files shell command does not apply the probe's current-size filter. The probes disable Git path quoting for non-ASCII filenames; the printed one-liners do not.

The [configuration reference](reference/configuration.md#complexity-settings) lists every scope and limit. The [command reference](reference/commands.md#clever-commands) explains standalone report paths, JSON output, and exit codes.

## Process and workspace coordination

This section describes the execution details useful when diagnosing an interrupted run.

`src/Actions/RunTask.php` measures the initial files, requests edits, validates and applies the proposal, starts verification and review, joins the results, and measures the final workspace. The reviewer receives saved file contents while Pest runs against the applied files. Before completion, Molly checks that the selected files still match the reviewed contents.

`src/Actions/EvaluateChanges.php` starts separate local processes for verification and review. Each branch records its kind, branch ID, attempt ID, local execution target, start and finish time, status, failure classification, and result path. The review branch also records `ollama` and the model name. Pest has no provider or model.

The internal `molly:check` command runs one branch and writes a result whose branch and attempt IDs must match the parent's request. Children do not update task or run records. A cancelled, timed-out, failed, unfinished, or missing branch cannot complete the run.

Branch deadlines use `molly.test_timeout` for Pest and `molly.timeout` for review. Each deadline is bounded to 1 through 3600 seconds, plus 10 seconds for startup and cleanup. Molly starts checks in owned process groups and stops the groups during cancellation and cleanup, including child processes whose original wrapper has exited.

Parallel checks require `posix_setsid` and `posix_kill`. Set `molly.parallel_checks` to `false` to run checks serially on a host without those functions. Molly does not silently change execution mode. Run `molly:doctor` after changing the setting.

`src/Workspace.php` holds `.molly/run.lock` for a writing run. Saved tasks also hold `.molly/task-{task-id}.lock` through state transitions and result persistence. Active check processes hold shared locks on `.molly/checks.lock`; `.molly/checks.lease` identifies the owning run. A new writer cannot overlap checks left running after a parent process exits. A delayed child with an earlier lease fails before executing the check.

Check input JSON files use `0600` permissions and are removed when the parent finishes joining the branches. Result files remain with the run evidence. The lock files require a shared local filesystem and are not a distributed coordination system.

## Limits of the evidence

Use Molly in a trusted, disposable checkout. The file allowlist restricts writer proposals. Pest still executes PHP with the current user's permissions. File permissions and workspace locks do not sandbox test code or isolate processes running as the same user.

The selected test file is writable by the model. Inspect the resulting assertions and diff before accepting the change. The final content check detects persistent changes to selected files; the check does not prove that tests avoided temporary changes or writes elsewhere.

Failed verification or review leaves applied edits available for inspection. Molly does not commit changes. An interrupted `running` state is uncertain, not successful. Saved tasks can use [stop and retry controls](tasks.md#stop-a-task) once the relevant locks are free.

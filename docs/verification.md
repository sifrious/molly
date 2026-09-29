# Verification

The agent does not decide whether a task is complete. Required verification does. If the Pest test named by the task fails, the task stays incomplete no matter what the model says about its own work.

This page explains what has to pass, how to read the result, and what Molly does when a check fails.

## What has to pass

When a task starts, Molly runs these steps in order:

1. Checks the proposal. It may change only the files you allowed, and it may not change the protected test.
2. Applies the proposal.
3. Checks the protected test's digest again.
4. Runs the required Pest test file.
5. Runs the Tarpit review, seven questions about the changed files.
6. Records Clever measurements of the code before and after the change.
7. Confirms the files still match what was reviewed.

Pest and Tarpit run at the same time by default. Both must finish with valid evidence. A passing Tarpit review never rescues a failed test, and a passing test does not hide a blocking Tarpit finding.

## Read the result

```bash
php artisan molly:show RUN_ID
php artisan molly:show RUN_ID --verbose
```

The short form summarises each check. The verbose form adds the Pest output, every Tarpit check with the model's reasoning, the measurements, and the timing of each branch. Reading a run never runs the model again.

For scripts, `--json` returns the whole saved report.

## Receipts

Every run that completes or stops leaves a receipt per verifier under `.molly/receipts/RUN_ID/`:

```bash
php artisan molly:receipt RUN_ID
```

A receipt records the verifier, its state (`PASS`, `FAIL`, `REVIEW_REQUIRED`, or `NOT_RUN`), the policy that applied, the failure action, a digest of the evidence, and timestamps. A verifier that never ran appears as `NOT_RUN` rather than disappearing. Receipt files are written once per run; a retry gets a new run ID and a new directory. Editing a receipt does not change the saved run.

When the agent is Ollama, Molly asks the configured loopback server which model answered, right after the model call: the Ollama version from `/api/version`, family, parameter size, quantization, and maximum context from `/api/show`, and the digest, loaded context length, memory size, and VRAM size from `/api/ps`. If the model is no longer loaded, the digest comes from `/api/tags`. The run report saves this as `model_identity` with the generation time in milliseconds, and every receipt carries it under `context.model_identity`. Any value Ollama did not report, including concurrency, which Ollama does not expose, is recorded as `unavailable`. `model_identity.sources` shows which requests answered.

## Secret redaction

Molly replaces secrets with `[redacted:NAME]` before it saves or prints run evidence. Three passes run in order:

- Values of environment variables whose names look secret, such as `APP_KEY`, `DB_PASSWORD`, `TYPESAFE_API_KEY`, `AWS_*`, or any name with a `KEY`, `SECRET`, `TOKEN`, `PASSWORD`, or `CREDENTIALS` segment. Molly reads them from its own process and from the workspace `.env`, which it never writes. Values shorter than 8 characters, and letter-only values shorter than 16, are left alone so ordinary words are not rewritten.
- Token shapes: GitHub tokens (`ghp_`, `gho_`, `ghu_`, `ghs_`, `ghr_`, `github_pat_`), `sk-` API keys, `base64:` Laravel keys, JWTs, AWS access key IDs, and PEM private key blocks. The replacement names the kind, such as `[redacted:github_token]`.
- In `effective_config` and the task settings snapshot, the whole value under a secret-named key, such as `api_key` or `webhook_secret`.

The pass runs on the run report and `effective_config` when they are saved and again when they are read, so reports saved by an older Molly are also redacted in `molly:show`, `molly:task`, the web pages, MCP, journals, and handoffs. It also runs on receipts before the evidence digest is computed, on lifecycle event payloads, on exported journals, on the Bloom contract request, on saved conversations, and on command error messages. Files in `storage/molly/RUN_ID`, such as the raw JUnit report, are kept as the tools wrote them. Task prompts are stored as typed, because the model needs them; they are redacted in journals and the Bloom contract. The context of a handoff is redacted before the envelope is printed or saved.

## The Pest check

Molly runs only the required test file, not your whole suite, and it wants real evidence: a JUnit report naming that file with at least one executed test and at least one assertion.

Any of these keeps the run from completing:

| Reason | Meaning |
| --- | --- |
| `tests_failed` | An assertion failed or a test errored. |
| `no_tests` | Pest found no test in the required file. A file without `<?php` looks like this. |
| `no_assertions` | A test ran but asserted nothing. |
| `tests_skipped_or_incomplete` | A skipped or incomplete test, including a Pest `->todo()`, is not passing evidence. |
| `test_timeout` | Pest ran longer than `molly.test_timeout` (120 seconds by default). |
| `junit_missing` or `junit_invalid` | Molly cannot trust the report Pest produced. |
| `false_green` | The report passed but did not name the required file. |

Run the whole suite yourself before committing:

```bash
vendor/bin/pest
```

## The protected test

The Pest test that defines a task is protected by default. Molly records its SHA-256 digest when the task is created, rejects any proposal that touches it, and fails the run if the file changes on disk during the attempt. The agent may change application code to make the test pass; it may not change the test.

When you want Molly to write the test itself, create a separate task with `--allow-test-edits`, then lock the result with `molly:lock-test` before the implementation task. [Tutorials](tutorials.md#write-the-test-first) shows the whole flow.

`molly:story` starts that flow from a plain-English story. It asks the configured model for numbered acceptance criteria, the files the implementation will change, and the Composer packages the behavior needs. It saves them on the task in `source.acceptance` and `source.scope` with the model name and a prompt digest, and creates the test-authoring task. It does not run anything else. `molly:lock-test --approve` then uses the derived files as the implementation scope. [Tasks](tasks.md#start-from-a-story) shows the commands.

### RED baseline

`molly:lock-test --approve` runs the locked test once before any implementation and saves the result in `source.test_lock.red_baseline`: the JUnit digest, test counts, failing test names, and one classification.

| Classification | Meaning |
| --- | --- |
| `missing_behavior` | Tests failed on assertions, missing routes, or missing application classes. The implementation may start. |
| `bootstrap_error` | The test could not run: a parse or fatal error, a missing test framework class, an unbound `TestCase`, a missing table without `RefreshDatabase`, a todo, skipped, or incomplete test (even beside failing tests), zero tests, or no JUnit report. |
| `already_passing` | The test passed before any implementation, so it proves nothing. |
| `not_recorded` | Molly could not run the test, for example because another run held the workspace. |

The same classifier checks the test at the end of every test-authoring run and saves the result in `report.authored_test`, with a cause and the affected tests for each test that cannot run. `molly:lock-test --approve` refuses a test that cannot run with `AUTHORED_TEST_BROKEN`, so a new lock records only `missing_behavior`, `already_passing`, `not_recorded`, or a `bootstrap_error` from a cause outside the test, such as `pest_missing`. See [Troubleshooting](troubleshooting.md#the-authored-test-cannot-run).

An implementation run of a locked task refuses to start with `RED_BASELINE_MISSING` or `RED_BASELINE_INVALID` until the baseline is `missing_behavior`. Fix the test file and run `molly:lock-test --approve` again; Molly locks the new digest and records a new baseline. A task created through `CreateTask` with `requireRedBaseline: false` skips the check. Tasks created with a hand-written test that was never locked are not checked. Run reports copy the baseline, and every receipt carries it under `context.red_baseline`.

## The Tarpit review

Tarpit asks the model seven questions about the changed files, before and after:

| Check | Looks for |
| --- | --- |
| A | Stored values that could be derived instead |
| B | Business decisions mixed with I/O, time, randomness, or mutation |
| C | Application decisions living in transport or rendering code |
| D | Hidden ordering or setup requirements |
| E | Abstractions with no current requirement |
| F | Excessive size, nesting, or duplication |
| G | Caches or indexes that add hidden behavior |

Each finding is classified as essential, pragmatic, or accidental. Only unresolved accidental complexity blocks completion; the rest stays attached to the run for you to read. Tarpit sees only the task and the selected files. It does not run tests or read the rest of the repository.

`REVIEW_INVALID` means the model returned an incomplete or inconsistent review. Molly treats that as missing evidence, not as a clean review. Small models produce it often; see [Ollama](ollama-quickstart.md#choosing-a-model).

## Clever measurements

Molly measures the code before and after the change with the bundled Clever probes and keeps each number separate. They describe the change; they are not a score and never block completion on their own. When Clever is disabled, missing, or fails, the run continues and the report records `complexity_before` and `complexity_after` with the status `unavailable` or `error` and the reason. Molly never reports a missing measurement as passing. You can run them yourself:

```bash
php artisan clever:scan
php artisan clever:owned-diff
php artisan clever:welds
php artisan clever:lonely-files
php artisan clever:hotspots
```

`owned-diff` counts lines and files in your application paths. `welds` finds literal constructor and static calls. `lonely-files` lists files with one Git author. `hotspots` combines size with recent commit activity. Each probe prints its scope, warnings, and the shell command you could use to check it by hand.

In `molly:show`, the Clever rows are marked advisory. A probe that failed is shown as `error` with its message, not as skipped, and a failed scan shows the reason `clever_scan_failed` with the error detail. Probe warnings, such as a shallow clone with partial history, appear in the short form. Caveats appear with `--verbose`, on the run page, and in the task journal. Hand-verify commands appear with `--verbose` and on the run page.

`lonely-files` and `hotspots` read the Git history of the measured directory only, with `git log --relative`, and report paths relative to that directory. An application in a subdirectory of its repository, such as `backend/` in a monorepo, gets the same results as one at the repository root. Commits to files outside that directory are not counted.

## False-green detection

Molly can also check that the test really depends on the code. When `MOLLY_FALSE_GREEN=true`, after Pest passes Molly replaces one selected production file with a throwing stub, runs the test again, and restores the file. If the test still passes, the run fails with `false_green`. A timeout or probe error is recorded as `REVIEW_REQUIRED` and is never counted as a pass. `molly.false_green.max_mutations` and `timeout_seconds` bound the check.

## When a check fails

The run ends as `failed`, the applied changes stay in your working tree, and the evidence is saved. Molly does not roll the files back and does not commit them. Read the run, then decide:

```bash
php artisan molly:show RUN_ID --verbose
php artisan molly:advice TASK
php artisan molly:retry TASK
```

A retry sends a bounded summary of the previous failure to the model so it can address it. A task may make three attempts by default (`molly.max_attempts`). Molly never retries on its own.

## Serial checks

Parallel checks need `posix_setsid` and `posix_kill`. If doctor reports `parallel_process_groups_unavailable`, set this in `config/molly.php` and run the checks one after another:

```php
'parallel_checks' => false,
```

## The sandbox

On Linux, the writer and the Pest process run inside a Landlock sandbox with private user and network namespaces. The sandbox can read the workspace, its `vendor` directory, and the project that owns that directory, and it can write only the selected files, the run's evidence directory, and a private temp directory. Doctor reports the sandbox as one check. macOS cannot provide it; [Getting started](getting-started.md#macos-and-the-sandbox) explains the override.

## Next

- [Tasks](tasks.md)
- [Troubleshooting](troubleshooting.md)
- [Configuration](reference/configuration.md#verification)

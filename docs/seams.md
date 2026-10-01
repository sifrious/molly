# Editable seam instructions

Molly can generate and verify tests for stateless JSON controllers and non-interactive Artisan commands. Both use the same saved planning review, protected-test lock, completion policy and receipt store. A coordinating model can choose cases and propose implementation changes. Only application actions record verification results.

The initial executable packs cover `laravel-framework/controllers` and `laravel-framework/commands` on Laravel 12 or 13. `resources/seams/catalogue.json` retains all 155 source guides and their 465 implementation issues, including their fixture and failure guidance. The other 153 adapters are pending. A catalogue entry does not mean that its generator or platform checks have run.

## Publish and inspect

Run these commands in the consuming application after installing Molly and running its migrations:

```bash
php artisan vendor:publish --tag=molly-seams
php artisan vendor:publish --tag=molly-seam-config
php artisan molly:seam list --json
php artisan molly:seam inspect --seam=laravel-framework/controllers --json
```

Each complete pack lives in `resources/molly/seams/<package>/<seam>/`. It contains `manifest.json`, `instructions.md`, `input.schema.json`, `plan.json`, `examples/good.pest.stub`, `examples/bad.pest.stub` and `templates/feature.pest.stub`. `publication.json` records the original published contents for upgrade comparisons. Commit these files with the application.

A complete application directory overrides the bundled pack. When no application directory exists, Molly uses the complete bundled pack. An incomplete directory, linked file, unsupported schema or incompatible package version stops execution. Molly never borrows individual files from another version. Inspection returns exact contents, source paths, versions, hashes and local changes.

Ordinary publishing preserves existing files. An explicit `vendor:publish --force` overwrites local edits. Molly does not invoke it. After a package upgrade, run:

```bash
php artisan molly:seam compare --seam=laravel-framework/controllers --json
```

The result includes original, local and new package contents for each file. It flags conflicting edits and missing publication history. Comparison does not write files and remains available for malformed local manifests. Resolve each conflict in the application pack, update its publication baseline deliberately, and create a new revision. Do not replace the original baseline before reviewing the comparison.

## Contracts and generation

The input contract names the target, whether it exists or is planned, the protected test path, approved production files, explicit cases and one reviewed negative control. Every case includes the expected result and its baseline response. The HTTP adapter asserts the exact JSON object and status through Laravel's HTTP kernel. The console adapter invokes the registered command through Artisan and checks output and exit status.

These adapters currently accept empty `accepted_state` and `fixtures` only. They do not set up authentication, database rows, interactive prompts or external services. Use a dedicated adapter for those requirements; do not describe setup in prose and assume it happened. Each generated case must fail on the declared baseline, or the contract must explicitly select `verification_path: already_implemented`. That alternative records intake GREEN and `historical_red: false`.

Inputs are JSON Schema 2020-12. External schema references are refused. Local schemas may tighten the adapter contract but cannot remove its required outcomes. Templates accept a PHP opening tag, comments and one `{{cases}}` placeholder. Registered PHP code renders the assertions, so a template edit cannot replace them with `expect(true)`.

## A controller example

Save this contract outside the tracked application files, or commit it before creating the revision. The example assumes `/ready` returns 404 before implementation and that no other production changes are pending.

```json
{
  "schema_version": 1,
  "name": "Readiness",
  "symbol": "App\\Http\\Controllers\\ReadyController",
  "path": "app/Http/Controllers/ReadyController.php",
  "target_state": "planned",
  "test_path": "tests/Feature/ReadinessTest.php",
  "production_paths": ["app/Http/Controllers/ReadyController.php", "routes/web.php"],
  "accepted_state": {},
  "fixtures": [],
  "cases": [{
    "id": "ready",
    "description": "reports readiness",
    "method": "GET",
    "uri": "/ready",
    "input": {},
    "expected": {"status": 200, "json": {"ready": true}},
    "before": {"status": 404}
  }],
  "negative_control": {
    "path": "app/Http/Controllers/ReadyController.php",
    "find": "'ready' => true",
    "replace": "'ready' => false",
    "case_id": "ready"
  }
}
```

Complete `molly:plan 'Add the readiness endpoint'`, then use its plan ID below. Read the returned preview and use its exact revision ID and digest. The capitalized values are placeholders for those returned fields.

```bash
php artisan molly:seam validate --seam=laravel-framework/controllers --contract=/tmp/ready.json --json
php artisan molly:seam plan --plan=PLAN_ID --seam=laravel-framework/controllers --contract=/tmp/ready.json --json
php artisan molly:seam preview --revision=REVISION_ID --json
php artisan molly:seam write --revision=REVISION_ID --digest=DIGEST --json
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=harness --key=harness-1 --wait --json
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=pre --key=pre-1 --wait --json
```

Continue only when each step returns `PASS`. PRE runs the protected test and requires assertion failures for every new case. A separate temporary test checks the declared baseline response. Syntax errors, missing dependencies, an unrelated 403 or a broken bootstrap cannot establish the expected 404-to-200 change. Molly removes the temporary test afterward.

A person reviews the generated test and authorizes its existing protected-test lock:

```bash
php artisan molly:lock-test TASK_ID --approve
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=lock --key=lock-1 --wait --json
```

Implement `ReadyController::__invoke()` to return `response()->json(['ready' => true])`, and register `Route::get('/ready', ReadyController::class)` in `routes/web.php`. Keep the protected test unchanged. Molly's model task execution also refuses implementation before the seam lock step passes.

```bash
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=implementation --key=implementation-1 --wait --json
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=post --key=post-1 --wait --json
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=sensitivity --key=sensitivity-1 --wait --json
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=cleanup --key=cleanup-1 --wait --json
php artisan molly:seam report --revision=REVISION_ID --json
```

POST requires every protected case to pass. Sensitivity copies the candidate into a private temporary directory, proves the unchanged copy passes, applies the exact reviewed replacement once, and requires the named case to fail by assertion. It removes the copy and records cleanup. It never mutates the working candidate. This proves sensitivity to that replacement; it is not a comprehensive mutation score or a tarpit review.

The receiving agent checks the report's artifact hashes and acknowledges its exact identities:

```bash
php artisan molly:seam acknowledge --revision=REVISION_ID --digest=DIGEST --candidate-digest=CANDIDATE_DIGEST --evidence-digest=EVIDENCE_DIGEST --recipient=receiving-verifier --json
php artisan molly:seam advance --revision=REVISION_ID --digest=DIGEST --step=handoff --key=handoff-1 --wait --json
php artisan molly:seam report --revision=REVISION_ID --json
```

Only the final report with `completed: true` establishes completion of this seam plan. A queued run, recommendation, acknowledgement or skipped check does not establish completion. The seam receipt covers the selected behavior cases. Molly's separate seven-check tarpit review and Clever measurements retain their own results and limitations.

## MCP and reconnecting

Start the existing local server with `php artisan mcp:start molly`. A compatible host must support stdio MCP tools with structured results, resources and prompts, and persist the revision ID, digest and request key between requests. Model tool support comes from the host. No provider SDK or provider credential is required by the seam actions.

The read-only `molly_seam_read` tool exposes `list`, `inspect`, `compare`, `validate`, `preview`, `status` and `report`. It refuses mutations server-side. Hosts can allow it independently of the mutating tool.

The `molly_seam` tool accepts these `operation` values:

| Operation | Result |
| --- | --- |
| `list`, `inspect`, `compare` | Packs, provenance and upgrade comparison |
| `validate`, `plan`, `preview` | Validated cases, frozen revision and proposed test |
| `write` | Authorized deterministic test and existing Molly task |
| `advance` | Durable attempt ID for the next permitted step |
| `status`, `report` | Results, artifact references, blockers and allowed next actions |
| `cancel`, `resume` | Cancellation request or recovery of the active attempt |
| `acknowledge` | Receipt of the exact handoff identities |

Fields use underscores in MCP and hyphenated CLI options. CLI `--contract` reads a JSON file; MCP `contract` is the JSON object. The resource templates are `molly://seams/{package}/{seam}` and `molly://seam-runs/{revision}`. The `seam-instructions-prompt` prompt takes `revision` and reads its frozen instructions and contract.

MCP advance always queues. Use a persistent queue connection with `retry_after` greater than the worker's 3600-second job timeout and run the existing worker. CLI `--wait` performs one bounded step locally. All paths preserve Molly's sandbox refusal. An unavailable sandbox or platform remains a blocker; this workflow does not change isolation configuration.

After reconnecting, read `status`. Repeat an uncertain request with the same key to retrieve its saved attempt. A failed check requires a new key for a linked retry. `resume` requeues an active attempt. If a worker stopped while running, recovery records `NOT_RUN` and requires a new attempt; it never silently reruns the interrupted test. A saved terminal result can repair a missing cursor update without repeating execution.

Cancellation preserves generated tests and evidence. A running bounded check finishes or times out, cleans up, and cannot advance the cancelled revision. This is cooperative cancellation, not a promise of immediate process termination.

## Frozen revisions and extensions

`src/Actions/CreateSeamPlan.php` freezes exact pack contents, template versions, behavior cases, baseline commit, working-tree hashes, environment identity, generated tests, capabilities and verification policies. Instructions, protected tests or expectations changing require a new revision. Earlier revisions and attempts remain readable. Changes outside approved production files stop execution. Production changes after POST invalidate its evidence.

`plan.json` selects typed `Sifrious\Molly\Seams\StepHandler` implementations by name, with schema-validated arguments and dependencies on earlier steps. Register additional names in `molly-seams.handlers`; Laravel's container resolves their dependencies. The built-in step names and order cannot be replaced or omitted. Handlers receive the frozen revision, saved attempt and owned evidence directory, and return a `StepResult`.

Handler registration is trusted application PHP. Do not expose it as client input. Markdown and JSON cannot register PHP classes or arbitrary shell commands. Approval and required/advisory policies remain in the existing `molly.verification.seam_<step>` and `molly.verification_actions.seam_<step>` configuration, frozen when the revision is created. Pack edits do not change them.

Attempts append lifecycle events with actor, correlation and causation IDs. They record expected and observed results, command arguments, exit codes, duration and sanitized artifacts under `.molly/receipts/<run-id>/`. Retries link to earlier attempts. Missing or modified artifacts block further execution and completion. The final seam acknowledgement concerns evidence in one workspace; it does not replace the separately approved Bloom workspace handoff.

## Structured errors

Each seam error returns `code`, `message`, `file`, `expected`, `observed`, `retryable`, `evidence` and `next_action`. Unknown observations are null. Result artifacts accompany executed failures in the attempt report.

| Code | Repair |
| --- | --- |
| `INSTRUCTION_PACK_INVALID` | Correct the named manifest, schema, template or plan field. |
| `INSTRUCTION_SCHEMA_UNSUPPORTED` | Use pack schema version 1. |
| `INSTRUCTION_OVERRIDE_INCOMPLETE` | Restore the complete application pack or remove the override deliberately. |
| `INSTRUCTION_UPGRADE_CONFLICT` | Compare original, local and package files and resolve each conflict. |
| `INSTRUCTION_DIGEST_CHANGED` | Review the edit and create a new revision. |
| `PLAN_REVISION_STALE` | Read the current revision or create a new one. |
| `PLAN_STEP_NOT_ALLOWED` | Complete the returned prerequisite; do not call POST directly. |
| `HANDLER_UNAVAILABLE` | Implement and register the typed adapter or step before selecting it. |
| `CLIENT_CAPABILITY_UNSUPPORTED` | Run on a worker with the required capability. Keep the check pending meanwhile. |
| `RUN_ALREADY_ACTIVE` | Reconnect to the returned run ID. |

Existing errors identify unavailable packages/environments, invalid input, changed tests, incorrect RED reasons, missing artifacts and failed assertions. A client-supplied `passed` field is invalid input.

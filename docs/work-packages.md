---
layout: default
title: Work packages
---

# Work packages

Use this page to track Molly's alpha work without Linear. The IDs match GitHub branches, commits, and pull requests.

Inspected Molly `main` at handoff: `ef7f234f8985d43b278a71a7bac9525d4ce29e56`.

Inspected Bloom `main` at handoff: `e03dd1387af7f3f4ae3b7731b8d4fa0843dd2372`. BloomCore now decodes Molly's task contract, binds it to the selected workspace, and gates Create and Merge until recorded approval. The Linux orb cannot compile the Swift app. Bloom still owns worktrees, diffs, agent sessions, and GitHub turns.

Protected tests are now the default. Writer and Pest isolation exists on hosts with Landlock and user/network namespaces. Amp thread observations are still not Orb execution.

## Status

| ID | Work | Status |
| --- | --- | --- |
| MOL-WP-01 | Versioned task, run, target, outcome, handoff, and lifecycle contracts | Contract types exist. PHP unit tests pin the task contract and Pest failure JSON to the Bloom Swift fixtures in `docs/handoffs/bloom-adapter/` |
| MOL-WP-02 | Bloom workspace, diff, approval, and PR loop | Headless contract export writes `.molly/bloom-contract.json`. An unpublished BloomCore adapter binds that file to the selected workspace, shows a Molly inspector tab, and refuses Create or Merge until approval. The adapter snapshot and next-agent instructions live in [docs/handoffs/COMBINE-BLOOM-AND-MOLLY.md](handoffs/COMBINE-BLOOM-AND-MOLLY.md). Bloom still opens the PR through its existing agent strip. The macOS app has not been built in this orb |
| MOL-WP-03 | Protected acceptance tests | Default protection and hash gates exist. `molly:lock-test --approve` freezes a test-authoring digest, records who approved it, and drops the Pest file from the writer scope |
| MOL-WP-04 | Writer and verifier sandboxes | Landlock plus network namespace exist; Pest still runs PHP; unsafe override is local-only |
| MOL-WP-05 | Explicit verifier failure actions and retry rules | Failure actions are recorded; retry restores the recorded baseline. Completed runs store immutable receipts. Pest cannot pass with zero assertions, a successful process plus failing JUnit, or JUnit that does not name the required test file |
| MOL-WP-06 | GitHub issue to Pest to PR traceability | Import is idempotent. Approved comments, PR bodies, and recorded PR or merge events exist. Molly still does not open or merge a pull request |
| MOL-WP-07 | Verified Orb execution targets | Local default and Orb refusal exist; verified Orb dispatch remains |
| MOL-WP-08 | Agent handoff inside Bloom | Headless envelopes exist. Recipients cannot widen scope, edit the protected test, or merge. Bloom child workspaces remain |
| MOL-WP-09 | Laravel AI classification compatibility (Jev) | Optional default-off Jev classification through Laravel AI. Pest, Tarpit, bounded retries, and completion authority stay deterministic. Do not use Molly-owned TypeSafe HTTP. Unreleased Laravel AI names are not used |
| MOL-WP-10 | Project graph and broader Laravel knowledge | Project graph indexing exists, including locked Pest approvals. Laravel knowledge covers queues, routing, testing, validation, the container, Eloquent, and events. NativePHP Desktop v2 and Mobile v4 are a separate namespace. Bundled tarpit notes are a separate namespace and are not a quality score. Implementation prompts receive bounded advisory neighborhoods. The local Molly UI shows a 40-node workspace overview with blockers first. `molly:decide` writes Git-tracked records under `docs/decisions/`. A visual Bloom view remains |
| MOL-WP-11 | Component previews | Optional local renderer exists. Unconfigured hosts stay unavailable. Visual evidence remains advisory |
| MOL-WP-12 | Compatibility matrix and packaging | Composer and CI now accept Laravel 12 and 13. Packagist publication remains |
| MOL-WP-13 | Docs site and tagged `v0.1.0-alpha.1` | Not started |

Do not call a work package done because a class or screen exists. The alpha is done only after the end-to-end proofs in the execution handoff.

## Merge order

1. MOL-WP-01
2. MOL-WP-03
3. MOL-WP-04
4. MOL-WP-05
5. MOL-WP-02, behind an experimental flag until the Bloom proof exists
6. MOL-WP-06
7. MOL-WP-08
8. MOL-WP-07 after local workspace behavior is proven
9. MOL-WP-09
10. MOL-WP-10 and MOL-WP-11
11. MOL-WP-12
12. MOL-WP-13

If a Molly change depends on a Bloom contract, merge and pin Bloom first. Do not claim a cross-repository success from unmerged branches.

## Linear inventory

Linear IDs are historical traceability only. This repository does not wait on Linear access.

Done:

- MME-5218 Laravel knowledge graph, queues first slice
- MME-5240 PASS / FAIL / REVIEW_REQUIRED / NOT_RUN

In progress elsewhere:

- MME-1806 two isolated local Orbs

Classification work for MOL-WP-09 uses the MME-5249 / MME-5252 family plus the file-level Jev / Laravel AI migration epics MME-5545 through MME-5575. Those epics cover Composer require, config, classification seams, agent/commit-review call sites, docs, and focused proofs. Do not invent new IDs. Do not keep draft or direct-HTTP TypeSafe notes in this index.

Jev file map (MME-5642). Every Jev production and test file has one owning ticket:

| File | Owns | Ticket |
| --- | --- | --- |
| `config/molly.php` (`molly.jev`) | Single default-off gate setting and policy | MME-5633 |
| `src/Classification/JevGate.php` | One authoritative gate: disabled / unavailable / unconfigured / ready | MME-5633 |
| `src/Classification/ChoiceClassifier.php`, `src/Classification/ChoiceClassification.php` | Molly-owned single-choice seam and typed answer | MME-5641 |
| `src/Classification/LaravelAiChoiceClassifier.php` | The only Laravel AI classification call (`Lab::TypeSafe`) | MME-5200 |
| `src/Classification/DetectLaravelAiClassification.php` | Exact-surface capability detection, version for provenance only | MME-5200 |
| `src/Classification/ResolveClassificationAdapter.php`, `LaravelAiClassificationAdapter.php`, `FallbackClassificationAdapter.php`, `ClassifyRunEvidence.php`, `ClassificationDecision.php` | Run-evidence classification behind the gate; deterministic follow-up stays authoritative | MME-5641 |
| `src/Actions/EvaluateWithTypeSafe.php` | Shared evaluation contract for advice, planning, and commit review | MME-5641 |
| `src/Actions/RecommendTaskNextStep.php`, `src/Actions/SuggestPlanReview.php`, `src/Actions/ReviewCommit.php` | Consumers that project the shared result without transport-specific behavior | MME-5637 |
| `src/Http/PlanController.php`, `src/Console/MollyAdviceCommand.php`, `src/Console/MollyReviewCommitCommand.php`, `src/Mcp/MollyTask.php`, `src/Mcp/MollyPlan.php`, `resources/views/advice.blade.php`, `resources/views/plan.blade.php` | Transports asking the gate and rendering the same states | MME-5637 |
| `tests/Unit/Classification/JevGateTest.php`, `tests/Feature/JevContractTest.php`, `tests/Support/FakeChoiceClassifier.php` | Truth-table proofs through Molly's seam in every lane | MME-5633 |
| `tests/Feature/TypeSafeEvaluationTest.php`, `tests/Unit/ClassificationAdapterTest.php`, `tests/Unit/Classification/DetectLaravelAiClassificationTest.php` | Live convergence proofs with `Classification::fake()` on the accepted Laravel AI commit | MME-5200 |
| `tests/Feature/TaskAdviceTest.php`, `TaskAdviceInterfacesTest.php`, `PlanningInterfacesTest.php`, `CommitReviewTest.php`, `McpToolsTest.php` | Transport parity for advice, planning, and commit review | MME-5637 |
| `.github/workflows/tests.yml` (`jev` lane) | Exact-commit Laravel AI verification lane | MME-5544 |
| `docs/reference/configuration.md` ("Jev states"), `docs/agents.md` | Documented truth table and agent rules | MME-5637 |

Release gate for Jev: ship only against a tagged `laravel/ai` release that includes the public classification / TypeSafe provider seam (PR #1049). Convergence proof stays: `Classification::fake()`, `Classification::assertClassified()`, and `Classification::assertNothingClassified()` in Molly tests — never a duplicated provider wire protocol. `MOLLY_JEV_ENABLED=false` is the default and performs no classification.

MME-5255 is Burdgen-specific and out of this Molly-only path. Penelope (MME-5144) and Bud (MME-5145) stay deferred.

Release blockers without a distinct Linear ticket stay on the work packages above: protected tests, writer and Pest sandboxes, Bloom task loop, approval before PR or merge, Packagist `v0.1.0-alpha.1`, and a Composer install without a custom VCS repository.

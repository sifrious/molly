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
| MOL-WP-01 | Versioned task, run, target, outcome, handoff, and lifecycle contracts | Contract types exist. BloomCore decodes the same JSON |
| MOL-WP-02 | Bloom workspace, diff, approval, and PR loop | Headless contract export writes `.molly/bloom-contract.json`. BloomCore binds that file to the selected workspace, shows a Molly inspector tab, and refuses Create or Merge until approval. Bloom still opens the PR through its existing agent strip. The macOS app has not been built in this orb |
| MOL-WP-03 | Protected acceptance tests | Default protection and hash gates exist. `molly:lock-test --approve` freezes a test-authoring digest, records who approved it, and drops the Pest file from the writer scope |
| MOL-WP-04 | Writer and verifier sandboxes | Landlock plus network namespace exist; Pest still runs PHP; unsafe override is local-only |
| MOL-WP-05 | Explicit verifier failure actions and retry rules | Failure actions are recorded; retry restores the recorded baseline. Completed runs store immutable receipts. Pest cannot pass with zero assertions or a successful process plus failing JUnit |
| MOL-WP-06 | GitHub issue to Pest to PR traceability | Import is idempotent. Approved comments, PR bodies, and recorded PR or merge events exist. Molly still does not open or merge a pull request |
| MOL-WP-07 | Verified Orb execution targets | Local default and Orb refusal exist; verified Orb dispatch remains |
| MOL-WP-08 | Agent handoff inside Bloom | Headless envelopes exist. Recipients cannot widen scope, edit the protected test, or merge. Bloom child workspaces remain |
| MOL-WP-09 | Laravel AI classification compatibility | Structured-output detection and an advisory Laravel AI adapter exist. They cannot upgrade a failed Pest run. Unreleased Laravel AI names are not used |
| MOL-WP-10 | Project graph and broader Laravel knowledge | Project graph indexing exists, including locked Pest approvals. Laravel knowledge covers queues, routing, testing, validation, the container, Eloquent, and events. NativePHP Desktop v2 and Mobile v4 are a separate namespace. Bundled tarpit notes are a separate namespace and are not a quality score. Implementation prompts receive bounded advisory neighborhoods. The local Molly UI shows a 40-node workspace overview with blockers first. A visual Bloom view remains |
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

Classification work for MOL-WP-09 uses the MME-5249 / MME-5252 family. Do not invent new IDs. MME-5255 is Burdgen-specific and out of this Molly-only path. Penelope (MME-5144) and Bud (MME-5145) stay deferred.

Release blockers without a distinct Linear ticket stay on the work packages above: protected tests, writer and Pest sandboxes, Bloom task loop, approval before PR or merge, Packagist `v0.1.0-alpha.1`, and a Composer install without a custom VCS repository.

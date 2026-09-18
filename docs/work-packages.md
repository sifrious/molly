---
layout: default
title: Work packages
---

# Work packages

Use this page to track Molly's alpha work without Linear. The IDs match GitHub branches, commits, and pull requests.

Inspected Molly `main` at handoff: `ef7f234f8985d43b278a71a7bac9525d4ce29e56`.

Inspected Bloom `main` at handoff: `e03dd1387af7f3f4ae3b7731b8d4fa0843dd2372`. Bloom has no Molly integration yet. Bloom owns worktrees, diffs, agent sessions, and pull requests. Molly owns the small-task contract, protected tests, verifiers, and evidence.

Protected tests are now the default. Writer and Pest isolation exists on hosts with Landlock and user/network namespaces. Amp thread observations are still not Orb execution. Bloom still has no Molly adapter.

## Status

| ID | Work | Status |
| --- | --- | --- |
| MOL-WP-01 | Versioned task, run, target, outcome, handoff, and lifecycle contracts | Contract types exist; Bloom adapter not started |
| MOL-WP-02 | Bloom workspace, diff, approval, and PR loop | Headless contract export exists; Bloom adapter and UI remain |
| MOL-WP-03 | Protected acceptance tests | Default protection and hash gates exist; Bloom test-author approval remains |
| MOL-WP-04 | Writer and verifier sandboxes | Landlock plus network namespace exist; Pest still runs PHP; unsafe override is local-only |
| MOL-WP-05 | Explicit verifier failure actions and retry rules | Failure actions are recorded; retry restores the recorded baseline |
| MOL-WP-06 | GitHub issue to Pest to PR traceability | Not started |
| MOL-WP-07 | Verified Orb execution targets | Not started |
| MOL-WP-08 | Agent handoff inside Bloom | Not started |
| MOL-WP-09 | Laravel AI classification compatibility | Not started |
| MOL-WP-10 | Project graph and broader Laravel knowledge | Not started |
| MOL-WP-11 | Component previews | Not started |
| MOL-WP-12 | Compatibility matrix and packaging | Not started |
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

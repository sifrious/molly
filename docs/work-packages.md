---
layout: default
title: Work packages
---

# Work packages

Use this page to track Molly's alpha work without Linear. The IDs match GitHub branches, commits, and pull requests.

Inspected Molly `main` at handoff: `ef7f234f8985d43b278a71a7bac9525d4ce29e56`.

Inspected Bloom `main` at handoff: `e03dd1387af7f3f4ae3b7731b8d4fa0843dd2372`. Bloom has no Molly integration yet. Bloom owns worktrees, diffs, agent sessions, and pull requests. Molly owns the small-task contract, protected tests, verifiers, and evidence.

Current Molly still adds the required Pest file to the writer's editable scope and is not a sandbox. Amp thread observations are not Orb execution. Those limits stay documented until the proofs below pass.

## Status

| ID | Work | Status |
| --- | --- | --- |
| MOL-WP-01 | Versioned task, run, target, outcome, handoff, and lifecycle contracts | In progress |
| MOL-WP-02 | Bloom workspace, diff, approval, and PR loop | Not started |
| MOL-WP-03 | Protected acceptance tests | Not started |
| MOL-WP-04 | Writer and verifier sandboxes | Not started |
| MOL-WP-05 | Explicit verifier failure actions and retry rules | Not started |
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

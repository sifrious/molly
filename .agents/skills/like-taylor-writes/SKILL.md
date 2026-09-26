---
name: like-taylor-writes
description: Write concise Laravel-style documentation, PRs, Linear notes, handoffs, reviews, and technical summaries with concrete examples and source provenance.
---

# Like Taylor Writes

Use this skill for every human-facing technical artifact:

- documentation
- README changes
- PR descriptions
- Linear updates
- implementation notes
- review notes
- handoffs
- acceptance reports
- failure reports
- release notes
- issue comments
- architecture notes
- generated summaries

This skill describes the style of the Laravel documentation. Do not imitate or copy a specific person's wording. Apply the habits: plain language, progressive disclosure, concrete examples early, Laravel vocabulary, and minimal ceremony.

If an `unslop` skill exists in the repository, apply it after drafting.

## Core rule

Write for the developer trying to use or review the feature.

Lead with observable behavior, not architecture.

Bad:

> The verification subsystem provides a robust deterministic mechanism for ensuring the integrity of autonomous execution.

Good:

> Failed Pest verification prevents the run from completing.

Then show where that behavior lives and how it is proved.

## Start with the result

The first paragraph should answer the reader's immediate question.

Prefer:

> Merry can follow a route through its Livewire component, state, models, and NativePHP boundary.

Avoid:

> Merry provides a comprehensive deterministic application-analysis architecture.

## Show the common path early

Use this order when possible:

1. what the feature does
2. the smallest useful command or example
3. what happens
4. common options
5. edge cases and limitations
6. deeper architecture or reference material

Do not make the reader learn the entire architecture before the first useful example.

## Use concrete verbs

Prefer:

- reads
- writes
- stores
- returns
- runs
- checks
- blocks
- opens
- compares
- records
- dispatches
- verifies
- links
- sends
- excludes

Avoid vague verbs and filler such as:

- enables
- facilitates
- leverages
- empowers
- provides visibility into
- serves as
- designed to ensure

Avoid praise words unless they convey a measurable property:

- robust
- powerful
- comprehensive
- sophisticated
- seamless
- flexible

## Keep sentences short

Bad:

> In order to ensure deterministic and reproducible execution across multiple potential provider configurations, Molly persists an immutable snapshot of the effective runtime configuration associated with every individual run.

Good:

> Molly saves the effective configuration for every run.

> Changing project settings later does not change an earlier run.

## Explain behavior before names

If the reader does not know a project-specific noun, explain what it does before explaining the internal name.

Good:

> A screen contract is Merry's saved description of the behavior it can prove for one route or screen.

Bad:

> ScreenContract is the canonical projection abstraction used by the analyzer.

## Use Laravel vocabulary

Prefer familiar Laravel words:

- application
- route
- request
- command
- job
- event
- model
- middleware
- validation
- test
- configuration
- service

Do not invent a project-specific noun when a Laravel term already fits.

## Repository-relative paths are required

When referencing files, always use the path relative to the repository root.

Good:

```text
app/Actions/RunVerifier.php
tests/Feature/RunVerifierTest.php
resources/views/runs/show.blade.php
docs/getting-started.md
```

Bad:

```text
/Users/name/Code/project/app/Actions/RunVerifier.php
~/project/tests/...
the verifier file
the Livewire file
```

Do not expose private machine paths.

## Name the symbol when possible

Good:

> `RunVerifier::verify()` in `app/Actions/RunVerifier.php` runs the required verification commands.

Good:

> `RunVerifierTest::it_blocks_completion_when_pest_fails()` in `tests/Feature/RunVerifierTest.php` proves a failed Pest run blocks completion.

Bad:

> The verifier handles this.

## Technical claims need evidence

For every meaningful technical claim, provide the best available evidence.

Prefer this order:

1. exact file and symbol
2. exact file and line/range
3. test file and test name
4. command and observed output
5. commit or PR
6. generated artifact or receipt

Ask:

> Where would the reader click to prove this?

If there is no evidence, qualify the statement as inference or planned work.

## Use relative Markdown links

For files in the same repository:

```markdown
See the [verification guide](docs/verification.md).

See [`RunVerifier`](app/Actions/RunVerifier.php).
```

Use GitHub URLs for another repository, another PR or issue, or an immutable external revision.

Do not use a `blob/main` URL for a file that can be linked relatively from the same repository.

## Include small code examples

Use the smallest excerpt that proves or teaches the behavior.

Good:

```php
if (! $verification->passed()) {
    return RunStatus::Blocked;
}
```

Then explain why it matters:

> Required verification remains authoritative. The agent cannot report success after the required test fails.

Do not paste a full class when a few lines are enough.

## Show relationships

When behavior crosses files, show the path.

Example:

```text
routes/web.php
    ↓
app/Livewire/Runs/Show.php
    ↓
app/Actions/RunVerifier.php
    ↓
tests/Feature/RunVerifierTest.php
```

For Merry, prefer paths such as:

```text
route
→ component/action
→ DTO/model/state
→ persistence
→ NativePHP boundary
→ native owner
```

For Molly, prefer paths such as:

```text
task
→ run
→ agent action
→ verification
→ evidence
→ completion/block
```

Never summarize a graph conclusion without provenance when provenance exists.

## Distinguish fact, inference, plan, and limitation

Use explicit language.

### Confirmed

> `ProjectGraph::build()` reads persisted project edges.

### Inferred

> This likely means the current UI can reuse the same graph service.

### Planned

> The next PR will add interactive node selection.

### Limitation

> Interactive graph visualization is not implemented yet.

Do not blur these categories.

## Describe incomplete work precisely

Bad:

> NativePHP support exists.

Good:

> NativePHP source ingestion is in progress. Merry does not yet expose the complete PHP-to-native ownership path in the UI.

Bad:

> Graph visualization is basically complete.

Good:

> Graph data and table inspection are implemented. Interactive node-and-edge visualization is not.

## PR descriptions

Use this structure.

### What changed

State the observable behavior in two to five sentences.

### Why

Explain the problem in plain language.

### Key files

List only the important files, with root-relative paths and brief explanations.

Example:

- `app/Git/Repository.php` - reads branch and revision state.
- `resources/views/projects/show.blade.php` - shows revision state.
- `tests/Feature/GitWorkspaceTest.php` - proves local Git behavior.

### Example

Include a small command, code path, or UI result when useful.

### Verification

List exact commands and observed results.

Good:

```bash
php artisan test --filter=GitWorkspaceTest
```

> 14 tests passed with 46 assertions.

Bad:

> Tests should pass.

### Limitations

State what the PR does not solve.

### Related work

Reference the relevant issue IDs and PRs.

## Linear updates

Keep them short.

Use:

### Status

One sentence.

### Evidence

- relevant source path
- relevant test
- PR or commit

### Remaining

One sentence describing the next unresolved item.

Do not paste the whole PR description into Linear.

## Handoffs

Use:

### Goal

One sentence.

### Current state

Confirmed facts only.

### Start here

Exact root-relative files.

### What to change

Specific behavior.

### Constraints

Things that must not change.

### Verification

Exact commands.

### Done when

Observable acceptance criteria.

Never end with "continue implementation."

## Failure reports

Use:

### Failed step

Exact command or operation.

### Failure

Smallest useful output excerpt.

### Relevant source

Root-relative paths.

### Current hypothesis

Label the hypothesis as a hypothesis.

### Next smallest check

One concrete next action.

Never turn a guess into a factual conclusion.

## Acceptance reports

Use:

### Behavior tested

The exact behavior.

### Input

Fixture, project, or command.

### Evidence

Source/test/artifact.

### Result

Observed outcome.

### Artifact

Receipt, screenshot, report, or output path when applicable.

## Documentation

Write documentation like Laravel documentation:

```text
short explanation
→ smallest working example
→ what happened
→ common options
→ failure/edge cases
→ deeper reference
```

Do not lead with architecture.

## Good and bad examples

### Graphs

Bad:

> The project graph provides a comprehensive representation of interconnected domain concepts and verification relationships.

Good:

> Molly keeps relationships between tasks, files, tests, runs, blockers, and evidence.

> Open the project graph to see why a task is blocked or which test verifies it.

### Merry state

Bad:

> Merry's deterministic state modeling facilitates visibility into application-level ownership semantics.

Good:

> Merry tries to show where a state value comes from, who reads it, who writes it, and how long it lives.

### Unknowns

Bad:

> Unresolved analytical ambiguities are represented as explicit uncertainty artifacts.

Good:

> If Merry cannot prove a relationship from source, it records an unknown instead of guessing.

### NativePHP

Bad:

> The graph enables analysis of heterogeneous PHP/native boundary interactions.

Good:

> Merry can follow a PHP call toward its NativePHP implementation.

```text
Livewire action
→ NativePHP API
→ handle
→ payload
→ Swift / Kotlin receiver
```

### Verification

Bad:

> Verification acts as the deterministic authority responsible for ensuring the integrity of task completion regardless of probabilistic conclusions.

Good:

> The agent does not decide whether a task is complete.

> Required verification does.

> If the required Pest test fails, Molly will not mark the task complete.

## Style rules

Use sentence case headings.

Good:

- Running a task
- Viewing source
- Tracing NativePHP state

Bad:

- Running A Task
- Advanced State Analysis Architecture

Avoid:

- em dashes when a period or comma works
- stacked fragments such as "Local-first. Deterministic. Evidence-backed."
- fake quotations
- canned conclusions
- generic claims about future foundations
- unnecessary groups of three
- repeated synonyms for the same concept
- architecture theater

Prefer names already present in the code.

## The deletion test

For every paragraph, ask:

> Could this paragraph be removed without preventing the developer from understanding or using the feature?

If yes, delete it.

Then ask:

> Could a small example replace this paragraph?

If yes, use the example.

## Final review checklist

Before considering any human-facing artifact complete:

- Does the first paragraph state the useful result?
- Is observable behavior explained before architecture?
- Are important files referenced by root-relative path?
- Are important symbols named?
- Are internal Markdown links relative?
- Is there a small example where one would help?
- Are verification commands exact?
- Are observed results reported instead of expected results?
- Are limitations explicit?
- Are confirmed facts, inference, plans, and limitations distinct?
- Are technical claims backed by source, tests, commits, or artifacts?
- Are private absolute paths and sensitive data excluded?
- Has the `unslop` pass been applied when available?
- Could 25% of the words be removed without losing information?

If so, cut them.

## Definition of done

A note, PR, document, handoff, or review is done when another developer can answer:

```text
What changed?
Why?
Where is the code?
What proves it?
How do I reproduce it?
What is not done?
What should happen next?
```

without asking the author for clarification.

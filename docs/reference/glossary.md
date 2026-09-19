---
layout: default
title: Glossary
---

# Glossary

Use this page when a Molly report or guide uses a term you do not recognize.

## Core work

| Term | Meaning |
| --- | --- |
| Host application | The Laravel application where Molly is installed and where you run Artisan. It stores Molly's task and run records. |
| Workspace | The checkout containing the files Molly may edit. It can be the host application or another local checkout. |
| File scope | The selected files an agent may propose changing. The required Pest test is protected unless the task explicitly allows test edits. |
| Task | A saved request with file scope, required test, optional nickname, and lifecycle state. |
| Task nickname | An optional readable task reference such as `health-check`. The task UUID remains the stable identifier. |
| Run | One execution attempt with its own status and evidence. |
| Attempt | A run linked to a saved task. Retrying creates another attempt. |
| Plan | Saved decisions for a larger piece of work. A plan does not edit code or prove implementation correctness. |

## Verification

| Term | Meaning |
| --- | --- |
| Protected test | The required Pest file whose SHA-256 digest is locked before implementation. Changing it fails the run. |
| Test lock | Human approval of a Pest digest after a test-authoring task. The next run cannot edit that file. |
| Failure action | What Molly does after a verifier does not pass: retry, fail, or warn. Policy and action stay separate fields. |
| Verification | The required Pest execution and JUnit evidence for the selected test file. A model statement is not verification. |
| False green | A passing JUnit report that did not identify the required Pest file or matching class. Molly fails the run with `false_green`. |
| Tarpit review | Seven model checks for unnecessary complexity in the supplied before-and-after files. It cannot override failed tests. |
| Finding | A Tarpit issue with a check, file, line, problem, recommendation, classification, and severity. |
| Essential complexity | Complexity required by the current requirement. |
| Pragmatic complexity | Complexity justified by a documented tradeoff. |
| Accidental complexity | Complexity that can be removed while preserving required behavior. It may block completion. |
| Clever measurement | One descriptive code measurement. Molly does not combine measurements into one quality score. |
| Evidence | Saved test results, review findings, hashes, measurements, and execution metadata used to inspect a run. |

## Source and component evidence

| Term | Meaning |
| --- | --- |
| Source snapshot | Metadata for selected files at one moment, including path and SHA-256 hash. It does not store source contents. |
| Component identity | `component:` plus a recognized Blade or Livewire source path. It identifies source, not a rendered UI component. |
| Task journal | Markdown export for one task at `.molly/journal/TASK_UUID.md`. |
| Project journal | `.molly/JOURNAL.md`, a generated view of saved tasks and attempts in one workspace. |
| Project glossary | `.molly/GLOSSARY.md`, including a Molly-managed section plus space for project-specific terms. |
| Project decision | A Git-tracked Markdown record under `docs/decisions/`. It is project memory, not run evidence. |

## Agents and providers

| Term | Meaning |
| --- | --- |
| Agent provider | The selected `amp` or `ollama` path for proposals and Tarpit review. |
| Molly MCP server | Local stdio server that exposes Molly actions to MCP clients. It has no HTTP route. |
| TypeSafe evaluation | Optional hosted evaluation used only when explicitly enabled for selected planning, advice, or commit-review paths. |
| Task advice | A recommendation based on saved state, attempt limits, and optional TypeSafe evidence. It never executes the recommendation. |

## Amp connection terms

| Term | Meaning |
| --- | --- |
| Amp thread | An Amp conversation identified by a `T-` UUID. |
| Task thread association | A user-recorded link between an Amp thread and a Molly task UUID. It is not provider-confirmed work. |
| `latest_recorded` | The thread's latest saved association names the requested task. |
| `prior_exact` | The thread was linked to the requested task before, but its latest saved association names another task. |
| Connection observation | A current read of Amp executor state for a requested thread. |
| Executor type | Raw Amp metadata. Molly preserves it without treating it as verified Orb identity. |

## Planned remote-execution terms

| Term | Meaning |
| --- | --- |
| Orb | A planned remote execution environment whose identity Molly does not currently verify. |
| Execution target | The place where work executes. Current Molly task execution is local; remote target selection is planned. |
| Handoff | A bounded envelope for a child Bloom workspace. The recipient cannot widen file scope, edit the protected test, or merge. |
| Bloom contract | Versioned JSON Molly writes to `.molly/bloom-contract.json`. Bloom binds it to the selected workspace and does not create another worktree. |
| Recorded pull request | A human-opened GitHub pull request URL stored after `molly:pr-opened --approve`. Molly does not open the pull request. |
| Recorded merge | A 40-character merge commit SHA stored after `molly:merged --approve`. Molly does not merge. |
| Project graph | Local SQLite relationships among tasks, tests, files, attempts, pull requests, commits, and blockers. Separate from Laravel documentation knowledge. |

For current task behavior, read [Manage tasks](../tasks.md). For planned remote work, read [Execution targets](../execution-targets.md).

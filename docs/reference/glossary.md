---
layout: default
title: Glossary
---

# Glossary

Terms you will meet in Molly's reports and pages, with what they mean in practice.

## Work

| Term | Meaning |
| --- | --- |
| Task | A saved request: what should change, the files the agent may edit, and the Pest test that proves it. |
| Run | One attempt at a task, with its own status, evidence, and receipts. Retrying makes a new run. |
| Attempt limit | How many runs a task may make, three by default. Counted from the test lock onward when a task wrote its own test. |
| Workspace | The checkout the task edits. Usually the application itself; `--workspace` names another. |
| File scope | The files a proposal may touch. The protected test is never in it. |
| Nickname | A readable name for a task, such as `ready-check`. The UUID stays the stable identifier. |
| Plan | Saved answers to five planning questions. It does not write code. |
| Display status | The task's state including human decisions: `awaiting_approval`, `approved`, `handed_off`, `merged`. |

## Verification

| Term | Meaning |
| --- | --- |
| Protected test | The required Pest file. Molly locks its digest and rejects any proposal that changes it. |
| Test lock | Your approval of a test the agent wrote, recorded by `molly:lock-test`. From then on the file is protected. |
| Verifier | One required check: `pest`, `tarpit`, `parallel_join`, or `false_green`. |
| Policy and failure action | Whether a verifier is required, and what happens when it fails (retry or fail). Both are in `config/molly.php`. |
| Receipt | The saved outcome of one verifier for one run, under `.molly/receipts/`. States are `PASS`, `FAIL`, `REVIEW_REQUIRED`, and `NOT_RUN`. |
| False green | A passing test report that did not name the required file, or a test that still passes when the code is broken. Fails the run. |
| Tarpit review | Seven questions the model answers about the changed files. Only an unresolved accidental finding blocks completion. |
| Finding | One Tarpit result: check, file, line, problem, recommendation, classification, severity. |
| Essential, pragmatic, accidental | How a finding is classified. Essential is required by the request, pragmatic is a documented trade-off, accidental can be removed. |
| Clever | The bundled measurements Molly records before and after a change. Numbers, not a score. |
| Evidence | Everything saved with a run: test output, findings, measurements, hashes, timings. |

## Files and records

| Term | Meaning |
| --- | --- |
| Source snapshot | The path and SHA-256 hash of each selected file at one moment. No source is stored. |
| Component | A selected Livewire class or view, or a Blade component or view, identified as `component:` plus its path. |
| Journal | `.molly/journal/TASK_UUID.md`, a Markdown export of one task. `.molly/JOURNAL.md` covers the workspace. |
| Decision | A Markdown record under `docs/decisions/` written by `molly:decide`. Project memory you commit. |
| Conversation | The saved messages between Molly and the agent for one run, under `~/.molly/conversations/`. |
| Effective config | The settings a run used, saved with the run when it was created. |

## Agents

| Term | Meaning |
| --- | --- |
| Agent | The model that proposes changes and answers the Tarpit review: Ollama or Amp. |
| Laravel AI | The package Molly uses to talk to Ollama and, for Jev, to TypeSafe. |
| Jev | TypeSafe's classification model. Molly may ask it one bounded question for advice, a plan suggestion, or a commit review. Off by default. |
| Jev gate | The single switch, `MOLLY_JEV_ENABLED`, and the states doctor reports: `disabled`, `unavailable`, `unconfigured`, `ready`. |
| MCP server | Molly's local stdio server for editors and chat clients. No HTTP route. |
| Sandbox | Landlock plus private user and network namespaces around the writer and Pest on Linux. |

## Bloom and GitHub

| Term | Meaning |
| --- | --- |
| Bloom | An optional macOS workspace and review tool. It owns the checkout and pull request screens; Molly owns the task and its evidence. |
| Bloom contract | `.molly/bloom-contract.json`, the versioned task description Bloom binds to a workspace. |
| Handoff | An envelope that passes a task to a child Bloom workspace with the same scope. |
| Recorded pull request, recorded merge | A URL or SHA a person supplied with `--approve`. Molly does not open or merge pull requests. |
| Thread link | A saved note that an Amp thread relates to a task. Not proof of execution. |
| Orb | A planned remote execution environment. Not shipped; see [Execution targets](../execution-targets.md). |

---
layout: default
title: Plan a collection of tasks
---

# Plan a collection of tasks

Start with the outcome, then use five questions to decide what the work needs. Molly saves your answers and the cited planning guide. A ready plan can produce several pending tasks. The questions work offline and do not change code.

## Start and resume a plan

```bash
php artisan molly:plan 'Let people inspect and retry failed tasks from one page.'
```

The interactive command uses Laravel Prompts and asks whether you want the guided Tarpit review. The questions keep complexity visible before implementation:

| Step | Decision |
| --- | --- |
| `outcome` | What must the user be able to do, and what is outside the task? |
| `state` | Which facts must persist, and which values can be computed? |
| `laravel` | Which existing application code and Laravel facilities already handle the work? |
| `boundaries` | What current requirement justifies a new interface, layer, package, or native integration? |
| `verification` | Which acceptance tests and Tarpit findings will show that the requirement is met? |

Molly saves each answer before asking the next question. Resume with the printed plan UUID:

```bash
php artisan molly:plan --resume=PLAN_ID
```

A description must contain 1 through 1,500 UTF-8 bytes. Each answer must contain 1 through 600 UTF-8 bytes. Answer the current question in order. Repeating an identical answer is accepted; changing an answer already saved is rejected. Create a new plan when the decisions need to change.

To skip the questions explicitly:

```bash
php artisan molly:plan 'Add a read-only task summary.' --skip-review
```

Skipped plans retain `review_mode: "skip"` and are ready for task creation. Skipping is not a passing Tarpit review. The implementation still needs its required test, run review, and complexity measurements.

## Use plans from scripts

Provide the description when using `--json` or `--no-interaction`. Creating a guided plan this way saves a draft and returns the next question without waiting for input:

```bash
php artisan molly:plan 'Show pending tasks.' --json --no-interaction
```

Save one answer using all three options below:

```bash
php artisan molly:plan --resume=PLAN_ID \
  --step=outcome \
  --answer='Show pending tasks on one screen. Editing tasks is outside this change.' \
  --json --no-interaction
```

Read `next_step` for the next step ID. A command with `--answer` saves that answer only and does not prompt for further answers. Resume preserves the original description and review choice; omit both a new description and `--skip-review`.

JSON contains `id`, `status`, `plan`, `next_step`, and `sources`. `status` is `draft` or `ready`; neither means that a task has run. See the [command reference](reference/commands.md).

## Create tasks from the plan

Open **Saved plans** in the [web interface](web-interface.md), then select the plan. Finish the questions or use a plan whose review was explicitly skipped. The task form asks for one small change, a workspace, selected files, and a required Pest test. Save a task, then return to the plan to create another.

The task description has a 1,000-byte limit before Molly adds the planning context. The normal task scope and size limits still apply. Saved tasks remain pending until you start them. Use `molly:name TASK NAME` to give a task a nickname after creation.

The [MCP task tool](agents.md#mcp-tools) also accepts `from_plan`. There is no `molly:plan` option that creates or starts tasks from the CLI.

Each task stores the plan UUID, guide version, review mode, description, answers, and citation metadata in `source`. Molly also includes the planning decisions in the task prompt. Those decisions are user context, not verification evidence.

## Read the source graph

Choose **Browse all bundled sources and their connections** on a plan, or call the MCP guide tool. `src/PlanningGuide.php` reads `resources/planning/guide.json` and the local source passages without network requests.

The bundle includes selected Laravel 13 passages, Mary Perry's public Tarpit material, and separate summaries for NativePHP Desktop v2 and Mobile v4. Each source retains its original URL, revision, and a digest of the bundled file. The bundle is a dated selection, not a complete manual. Check installed package versions before using a documented API.

Molly selects up to five references using the current questions and matching topics. A matching topic suggests material to consult; it does not justify adding a package, queue, event, or interface. The questions and connections between sources are Molly's guidance, not an endorsement by the source authors.

Source permissions, attribution, and the 128 KiB bundle limit are recorded in [the source manifest](https://github.com/sifrious/molly/blob/main/resources/planning/MANIFEST.md). A new guide version requires a new plan before adding answers or creating tasks. Existing answers remain readable.

## Ask Jev for a suggestion

The ordinary guided review works offline. An optional TypeSafe evaluation can suggest which of the five planning areas deserves another look. Configure [TypeSafe settings](reference/configuration.md#typesafe-evaluation-settings), then choose **Ask Jev for a Tarpit suggestion** on the plan or use the MCP plan tool's `suggest` operation.

The request sends the plan description, saved answers, guide version, and selected source excerpts to TypeSafe. Each selected excerpt is limited to 3,000 UTF-8 bytes. The encoded evidence must fit within 32 KiB; larger evidence is rejected before sending.

Molly saves the result, confidence, evaluation time, and answers used for that evaluation. An accepted suggestion points to a guide question and its sources. The web page marks a suggestion as outdated when your answers have changed since the request. A suggestion does not answer a question, advance the plan, or start a task.

Provider errors, malformed answers, low confidence, and a changed guide remain visible as `needs_review`. Automatic retry loops and remote Orb execution are not part of planning.

The shared actions are `src/Actions/CreatePlan.php`, `src/Actions/AnswerPlan.php`, `src/Actions/CreateTaskFromPlan.php`, and `src/Actions/SuggestPlanReview.php`. CLI, web, and MCP handlers use those actions.

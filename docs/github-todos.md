# GitHub acceptance criteria to Pest todos

This tutorial turns the acceptance criteria in a GitHub issue into a Pest file with one todo per criterion, has the model replace each todo with a real test, locks that test, and then implements the issue. Molly writes the todo file itself. No model call is involved in that step.

## Prerequisites

- Molly installed and `php artisan molly:doctor` passing, as in [Getting started](getting-started.md)
- The GitHub CLI logged in: `gh auth status` exits `0`
- An issue with a list under a heading named "Acceptance criteria"

## The issue

Molly reads the list under the first line that starts with "Acceptance criteria", either a Markdown heading or bold text. List items can be `-`, `*`, `+`, or numbered, with or without a checkbox. The section ends at the next heading. Indented sub-items are notes, not criteria.

This is the body of the example issue, `https://github.com/sifrious/molly-demo/issues/42`, titled "Add a readiness route":

```markdown
Load balancers need a route that says the application is up.

## Acceptance criteria

- [ ] GET /ready returns HTTP 200.
- [ ] The response body is exactly {"ready":true}.
- [ ] A guest's request to GET /ready succeeds without signing in.

## Notes

- Keep the existing routes.
```

The issue is an example. Use your own repository's issue URL in the commands below. Molly's tests use the same issue as a JSON fixture, `tests/Fixtures/github/issue-42.json`, in place of the `gh api` response.

## Import with todos

```bash
php artisan molly:import https://github.com/OWNER/REPO/issues/42 \
  --name=ready-issue \
  --test=tests/Feature/ReadyTest.php \
  --file=routes/web.php \
  --allow-test-edits \
  --todos
```

`--todos` needs `--allow-test-edits`, because the next run must rewrite the file. Without it, Molly stops with `TODOS_NEED_TEST_EDITS` before it asks GitHub for anything.

Molly reads the issue with `gh api`, writes `tests/Feature/ReadyTest.php`, and saves a pending test-authoring task. The report ends with:

```text
Wrote 3 Pest todos to tests/Feature/ReadyTest.php. The test-authoring run replaces them with executable tests.
Start this task with php artisan molly:start ready-issue.
```

The file Molly wrote:

```php
<?php

// Acceptance criteria from https://github.com/sifrious/molly-demo/issues/42
// Molly wrote one todo per criterion. Replace each todo with a test that
// asserts the behavior. Pest reports a todo as incomplete, never as passed.

it('criterion 1: GET /ready returns HTTP 200.')->todo();
it('criterion 2: The response body is exactly {"ready":true}.')->todo();
it('criterion 3: A guest\'s request to GET /ready succeeds without signing in.')->todo();
```

## Criteria and tests

Each criterion becomes one test whose description starts with its number. The number is how you trace a test back to the issue, in the file, in `molly:show`, and in the JUnit evidence.

| Criterion in the issue | Pest test description |
| --- | --- |
| GET /ready returns HTTP 200. | `criterion 1: GET /ready returns HTTP 200.` |
| The response body is exactly {"ready":true}. | `criterion 2: The response body is exactly {"ready":true}.` |
| A guest's request to GET /ready succeeds without signing in. | `criterion 3: A guest's request to GET /ready succeeds without signing in.` |

The task stores the same mapping with the criteria, the test descriptions, and a SHA-256 digest of the todo file. `molly:task --json` prints it under `task.source.acceptance`:

```bash
php artisan molly:task ready-issue --json
```

## Todos never pass

Pest reports a todo as incomplete. Molly runs Pest with `--fail-on-skipped` and `--fail-on-incomplete`, and `VerifyChanges` returns `failed` with the reason `tests_skipped_or_incomplete` whenever any test in the file is a todo, skipped, or incomplete. That holds when the other tests pass, too. A file of todos cannot complete a task.

Locking the todo file fails for the same reason:

```bash
php artisan molly:lock-test ready-issue --approve --file=routes/web.php
```

```text
AUTHORED_TEST_BROKEN: Molly did not lock tests/Feature/ReadyTest.php because it cannot run.
```

The refusal names the `tests_skipped` cause. The task stays a test-authoring task.

## Replace the todos

Start the authoring run:

```bash
php artisan molly:start ready-issue
```

The model receives the todo file as the current contents of the test and a prompt that lists the numbered criteria. It must return the whole file with every todo replaced. Molly accepts only Pest `it()` or `test()` cases that target the application; it rejects a file that defines routes, registers components, or builds a schema, with `TEST_AUTHORING_INVALID`. A good result looks like this:

```php
<?php

it('criterion 1: GET /ready returns HTTP 200.', function () {
    $this->get('/ready')->assertOk();
});

it('criterion 2: The response body is exactly {"ready":true}.', function () {
    expect($this->get('/ready')->getContent())->toBe('{"ready":true}');
});

it('criterion 3: A guest\'s request to GET /ready succeeds without signing in.', function () {
    $this->assertGuest();
    $this->get('/ready')->assertOk();
});
```

The run exits `1`. That is expected: the tests exist and `/ready` does not. Read the failure to confirm it is a 404 and not a broken test:

```bash
php artisan molly:show RUN_ID --verbose
```

If the model left a todo, retry with `php artisan molly:retry ready-issue`, or edit the file yourself.

## Lock the test and implement

```bash
php artisan molly:lock-test ready-issue --approve --file=routes/web.php \
  --reason='Each test covers one numbered criterion from issue 42.'
php artisan molly:start ready-issue
```

The lock runs the test once more, records it as failing for missing behavior, and protects it. From here the task is an ordinary implementation task. The model may change only `routes/web.php`; a proposal that touches `tests/Feature/ReadyTest.php` fails with `PROTECTED_TEST_CHANGED`. The task completes only when all three tests pass and the Tarpit review has no blocking finding.

After the run completes and you have reviewed it, [From a GitHub issue to a task](tutorials.md#from-a-github-issue-to-a-task) shows the approval, pull request text, and issue comment.

## Troubleshooting

`ISSUE_CRITERIA_MISSING` means the issue has no list under an "Acceptance criteria" heading. Add one to the issue and import again. Molly writes nothing and saves no task.

`TODOS_TEST_EXISTS` means the test file already exists. Molly never overwrites a test with todos. Pick a new `--test` path, or import without `--todos` and write the test yourself.

`ISSUE_CRITERIA_INVALID` means the issue lists more than 30 criteria or a criterion longer than 500 characters. Split the issue.

`ISSUE_ALREADY_IMPORTED` means this issue already has a task with other settings. Importing it again with the same settings returns the existing task and leaves the test file alone.

## Proof

`tests/Feature/GitHubPestTodosTest.php` runs every step on this page against a Laravel-shaped workspace with a real Pest process. Only `gh api` is faked, with the issue fixture. A test there fails if the issue body, the todo file, or the authored tests on this page stop matching `tests/Fixtures/github/`.

## Next

- [Write the test first](tutorials.md#write-the-test-first) is the same flow without an issue.
- [Verification](verification.md) lists everything that must pass.

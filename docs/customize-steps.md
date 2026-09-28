# Customize a step with Laravel AI

Molly runs three model steps, and each one is a Laravel AI agent class. You can replace any of them with a subclass that your application binds in the service container. This page replaces the change writer with one that follows your team's rules, then shows what Molly still checks after the replacement.

Changing the provider, the model, or a timeout is configuration, not a custom step. [Tune Laravel AI](tutorials.md#tune-laravel-ai) covers those settings.

## The default steps

| Step | Agent class | Called by | Returns |
| --- | --- | --- | --- |
| Propose a change | `Sifrious\Molly\Agents\ChangeWriter` | `Sifrious\Molly\Actions\GenerateChanges` | `summary` and `files`, each file a `path` and full `content` |
| Review the change | `Sifrious\Molly\Agents\TarpitReviewer` | `Sifrious\Molly\Actions\ReviewChanges` | `checks` A through G and `findings` |
| Turn a story into criteria | `Sifrious\Molly\Agents\AcceptanceWriter` | `Sifrious\Molly\Actions\CreateTaskFromStory` | `criteria`, `files`, and `required_packages` |

Each action resolves its agent with Laravel AI's `make()` method, for example `ChangeWriter::make()`. `make()` asks the container for the class, so a container binding decides which class runs. With no binding, Molly uses its own classes. The binding applies to both agents Molly supports: with Ollama, Laravel AI sends the subclass's instructions to the model; with Amp, `Sifrious\Molly\Agents\AmpResponse` builds the Amp prompt from the same `instructions()` and `schema()` methods.

## Prerequisites

- Molly installed and `php artisan molly:doctor` passing, as in [Getting started](getting-started.md)
- A task to run, such as the `ready-check` task from [Create your own task](getting-started.md#create-your-own-task)

## Write the agent

Create `app/Ai/Agents/TeamChangeWriter.php`:

```php
<?php

namespace App\Ai\Agents;

use Sifrious\Molly\Agents\ChangeWriter;

class TeamChangeWriter extends ChangeWriter
{
    public function instructions(): string
    {
        return parent::instructions()."\n".<<<'TEXT'
        Team rules for this application:
        Validate controller input with a Form Request class.
        Return JSON with response()->json().
        TEXT;
    }
}
```

The class extends `ChangeWriter` and appends to its instructions instead of replacing them. Molly's own instructions tell the model which files it may change and that the required test is read-only. Keep them, even though Molly enforces both rules again after the model answers.

The replacement must extend the Molly class it replaces. `make()` declares a `static` return type, so binding `ChangeWriter` to a class that does not extend it throws a `TypeError` before any model request.

## Bind it

In `app/Providers/AppServiceProvider.php`:

```php
use App\Ai\Agents\TeamChangeWriter;
use Sifrious\Molly\Agents\ChangeWriter;

public function register(): void
{
    $this->app->bind(ChangeWriter::class, TeamChangeWriter::class);
}
```

Bind `TarpitReviewer::class` or `AcceptanceWriter::class` the same way to replace the review or the story step.

## Run it

Nothing about the command changes:

```bash
php artisan config:clear
php artisan molly:start ready-check
```

## Expected result

`molly:start` prints the same report it prints with the default writer, and exits `0` only when the run completes. The proposal now comes from `TeamChangeWriter`. The run report does not record the agent class, so check the binding with a test in your application. This one uses Laravel AI's agent fake, so it makes no model request:

```php
use App\Ai\Agents\TeamChangeWriter;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Agents\ChangeWriter;

it('uses the team change writer', function () {
    TeamChangeWriter::fake([[
        'summary' => 'Add the route.',
        'files' => [['path' => 'routes/web.php', 'content' => '<?php']],
    ]]);

    app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php');

    expect(ChangeWriter::make())->toBeInstanceOf(TeamChangeWriter::class);
    TeamChangeWriter::assertPrompted(fn ($prompt) => $prompt->agent instanceof TeamChangeWriter);
});
```

Laravel AI keys fakes by the concrete class, so fake `TeamChangeWriter`, not `ChangeWriter`. Molly's own suite runs the same checks in `tests/Feature/CustomAgentStepTest.php`, and a test there fails if the `TeamChangeWriter` example on this page stops matching the tested class.

## What a custom step cannot change

The agent only returns data. `GenerateChanges`, `ReviewChanges`, and `RunTask` decide what happens to it, and none of those decisions move into your class:

| What the custom step returns | What Molly does |
| --- | --- |
| A file outside the task's allowed files | Rejects the whole proposal with `GENERATION_INVALID: The model proposed a file outside the allowed paths.` and writes nothing |
| A change to the required Pest test | Rejects the proposal with `PROTECTED_TEST_CHANGED` unless the task is a test-authoring task |
| A summary such as "All tests pass. The task is complete." or extra keys like `status` or `tests_passed` | Keeps only `summary` and `files`. Pest still runs, and a failing test leaves the run `failed` |
| A review with fewer than seven checks, or a blocking finding that is not accidental complexity | Rejects the review with `REVIEW_INVALID` |
| A clean review while Pest fails | The run fails. A passing review never rescues a failing test |

`tests/Feature/CustomAgentStepTest.php` covers each row. The run completes only when the required Pest test passes and the Tarpit review has no blocking finding. [Verification](verification.md) has the full list.

## Troubleshooting

A `TypeError` that names `ChangeWriter::make()` means the bound class does not extend `Sifrious\Molly\Agents\ChangeWriter`. Extend it.

`GENERATION_INVALID: The model did not return a valid change proposal.` means the model's answer lost the expected shape. Keep the parent `schema()` and do not ask for diffs; Molly needs complete file contents.

If the report looks the same as before, run `php artisan config:clear` and check the binding with the test above. A binding in a provider that the application does not load has no effect.

## Extension points

| Extension point | Default | How to replace it | Status |
| --- | --- | --- | --- |
| Change writer | `ChangeWriter` | Bind a subclass, as on this page | Supported |
| Tarpit reviewer | `TarpitReviewer` | Bind a subclass | Supported. Molly still validates all seven checks |
| Story criteria writer | `AcceptanceWriter` | Bind a subclass | Supported |
| Provider, model, timeouts | Ollama with `molly.model` | `config/ai.php` and `config/molly.php` | Supported. Configuration, not a custom step |
| Pest verification, protected tests, completion | `VerifyChanges`, `DecideRunCompletion` | None | Not an extension point. These decide whether a task is done, and a binding that replaces them is unsupported |

[Ownership and support](overview.md#ownership-and-support) lists what each integration does for Molly, including Prism and Rudy.

## Next

- [Agents and MCP](agents.md) explains the Ollama and Amp paths.
- [Verification](verification.md) lists what must pass.

<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\AnswerPlan;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\CreateTaskFromPlan;
use Sifrious\Molly\Actions\ShowPlan;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

it('saves a resumable planning draft without invoking a model or creating executable work', function () {
    ChangeWriter::fake()->preventStrayPrompts();

    $plan = app(CreatePlan::class)->handle('Add a mobile task list.');
    $resumed = app(ShowPlan::class)->handle($plan->id);

    expect($resumed->description)->toBe('Add a mobile task list.')
        ->and($resumed->answers)->toBe([])
        ->and($resumed->completed())->toBeFalse()
        ->and($resumed->nextStep()['id'])->toBe('outcome')
        ->and(Task::count())->toBe(0)
        ->and(Run::count())->toBe(0);
    ChangeWriter::assertNeverPrompted();
});

it('can explicitly skip the guided review without claiming it was performed', function () {
    $plan = app(CreatePlan::class)->handle('Use the existing task scope.', false);

    expect($plan->review_mode)->toBe('skip')->and($plan->completed())->toBeTrue()
        ->and($plan->answers)->toBe([])->and($plan->nextStep())->toBeNull();
});

it('rejects an invalid description before saving a draft', function (string $description) {
    expect(fn () => app(CreatePlan::class)->handle($description))->toThrow(RuntimeException::class, 'PLAN_DESCRIPTION_INVALID');
    expect(Plan::count())->toBe(0);
})->with(['empty' => ['  '], 'over limit' => [str_repeat('x', 1501)], 'byte limit' => [str_repeat('é', 751)], 'invalid encoding' => ["bad\xff"]]);

it('saves a decision once and rejects out of order or overwritten decisions', function () {
    $plan = app(CreatePlan::class)->handle('Display pending tasks.');
    $answer = app(AnswerPlan::class);
    $answer->handle($plan->id, 'outcome', 'Show the pending tasks on one screen.');
    $repeat = $answer->handle($plan->id, 'outcome', 'Show the pending tasks on one screen.');
    expect($repeat->nextStep()['id'])->toBe('state');

    expect(fn () => $answer->handle($plan->id, 'verification', 'Test the list.'))->toThrow(RuntimeException::class, 'PLAN_STEP_INVALID');
    expect(fn () => $answer->handle($plan->id, 'outcome', 'Overwrite the original.'))->toThrow(RuntimeException::class, 'PLAN_STEP_INVALID');
    expect($plan->fresh()->answers)->toBe(['outcome' => 'Show the pending tasks on one screen.']);
});

it('rejects invalid answers without advancing the review', function (string $answer) {
    $plan = app(CreatePlan::class)->handle('Show pending tasks.');
    expect(fn () => app(AnswerPlan::class)->handle($plan->id, 'outcome', $answer))->toThrow(RuntimeException::class, 'PLAN_ANSWER_INVALID');
    expect($plan->fresh()->answers)->toBe([]);
})->with(['empty' => [' '], 'oversized' => [str_repeat('a', 601)], 'byte limit' => [str_repeat('é', 301)], 'invalid encoding' => ["bad\xff"]]);

it('keeps a skipped or missing plan from accepting review answers', function () {
    $plan = app(CreatePlan::class)->handle('Show the task list.', false);
    expect(fn () => app(AnswerPlan::class)->handle($plan->id, 'outcome', 'List tasks.'))->toThrow(RuntimeException::class, 'PLAN_STEP_INVALID');
    expect(fn () => app(AnswerPlan::class)->handle('missing', 'outcome', 'List tasks.'))->toThrow(RuntimeException::class, 'PLAN_NOT_FOUND');
});

it('does not apply a changed guide to an existing plan silently', function () {
    $plan = app(CreatePlan::class)->handle('Add a task list.');
    $plan->update(['guide_version' => 'previous-edition']);

    expect(fn () => app(AnswerPlan::class)->handle($plan->id, 'outcome', 'List tasks.'))->toThrow(RuntimeException::class, 'PLAN_GUIDE_CHANGED');
    expect($plan->fresh()->answers)->toBe([]);
});

it('requires completed planning before materializing a task', function () {
    $plan = app(CreatePlan::class)->handle('Add a task list.');
    expect(fn () => app(CreateTaskFromPlan::class)->handle($plan->id, 'List tasks.', '/missing', [], ''))
        ->toThrow(RuntimeException::class, 'PLAN_INCOMPLETE');
    expect(Task::count())->toBe(0);
});

it('copies completed planning decisions and source provenance into a pending task', function () {
    $directory = sys_get_temp_dir().'/molly-plan-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory.'/app');
    try {
        $plan = app(CreatePlan::class)->handle('Add a NativePHP mobile task list.');
        $answers = ['outcome' => 'List pending tasks.', 'state' => 'Use the existing task records.', 'laravel' => 'Use Eloquent and NativePHP mobile.', 'boundaries' => 'No new interface.', 'verification' => 'Test an empty list and a pending task.'];
        foreach ($answers as $step => $value) {
            $plan = app(AnswerPlan::class)->handle($plan->id, $step, $value);
        }

        writeProtectedTest($directory, 'tests/TaskListTest.php');
        $task = app(CreateTaskFromPlan::class)->handle($plan->id, 'Render pending tasks.', $directory, ['app/TaskList.php'], 'tests/TaskListTest.php');

        expect($plan->completed())->toBeTrue()
            ->and($task->status)->toBe('pending')
            ->and($task->source['plan_id'])->toBe($plan->id)
            ->and($task->source['planning']['decisions'])->toBe($answers)
            ->and(array_column($task->source['citations'], 'id'))->toContain('nativephp-mobile')
            ->and($task->prompt)->toContain('No new interface.', 'Render pending tasks.')
            ->and(Run::count())->toBe(0)
            ->and(File::exists($directory.'/tests/TaskListTest.php'))->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
});

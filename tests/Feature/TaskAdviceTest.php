<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

beforeEach(function (): void {
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'advice-test-key']);
});

function adviceTask(string $status = 'failed'): Task
{
    return Task::create(['nickname' => 'health-check', 'prompt' => 'Add a health endpoint.', 'workspace' => sys_get_temp_dir(), 'paths' => ['routes/web.php', 'tests/Feature/HealthTest.php'], 'test_path' => 'tests/Feature/HealthTest.php', 'status' => $status]);
}

function adviceRun(Task $task, string $status = 'failed', ?array $report = null): Run
{
    return $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => $status, 'report' => $report ?? ['verification' => ['status' => 'failed', 'tests' => 1, 'assertions' => 2, 'failures' => 1, 'errors' => 0, 'skipped' => 0], 'review' => ['findings' => []]]]);
}

function adviceResponse(string $choice = 'retry', float $confidence = 0.9): array
{
    return ['model' => 'jev-latest', 'answers' => ['next_action' => ['type' => 'choice', 'choice' => $choice, 'confidence' => $confidence, 'probabilities' => array_replace(array_fill_keys(['continue', 'retry', 'stop', 'needs_review'], 0), [$choice => 1])]]];
}

it('returns deterministic state guidance without consulting TypeSafe', function (string $state, string $action, bool $retryAllowed, ?string $runState): void {
    $task = adviceTask($state);
    $run = $runState === null ? null : adviceRun($task, $runState);
    $before = $task->fresh()->getRawOriginal();

    $result = app(RecommendTaskNextStep::class)->handle('HEALTH-CHECK');

    expect($result['next_action'])->toBe($action)
        ->and($result['retry_allowed'])->toBe($retryAllowed)
        ->and($result['status'])->toBe('deterministic')
        ->and($result['provider']['status'])->toBe('not_requested')
        ->and($result['confidence'])->toBeNull()
        ->and($result['task_id'])->toBe($task->id)
        ->and($task->fresh()->getRawOriginal())->toBe($before)
        ->and($task->runs()->count())->toBe($run === null ? 0 : 1);
    Http::assertNothingSent();
})->with([
    'pending' => ['pending', 'start', false, null],
    'running' => ['running', 'wait', false, 'running'],
    'completed' => ['completed', 'done', false, 'completed'],
    'stopped' => ['stopped', 'inspect', true, 'stopped'],
    'failure without a run' => ['failed', 'inspect', true, null],
]);

it('blocks exhausted tasks before requesting a model recommendation', function (string $status): void {
    config(['molly.max_attempts' => 1]);
    $task = adviceTask($status);
    $run = adviceRun($task, $status);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['next_action'])->toBe('stop')
        ->and($result['retry_allowed'])->toBeFalse()
        ->and($result['command'])->toBeNull()
        ->and($result['reason'])->toContain('all 1 allowed attempts')
        ->and($result['observed'])->toBe(['task_status' => $status, 'attempt_count' => 1, 'max_attempts' => 1])
        ->and($run->fresh()->report['advice'])->toBe($result)
        ->and($task->fresh()->status)->toBe($status);
    Http::assertNothingSent();
})->with(['failed', 'stopped']);

it('returns inspection advice for an invalid attempt limit', function (mixed $limit): void {
    config(['molly.max_attempts' => $limit]);
    $task = adviceTask();
    adviceRun($task);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['next_action'])->toBe('inspect')
        ->and($result['retry_allowed'])->toBeFalse()
        ->and($result['observed']['max_attempts'])->toBeNull()
        ->and($result['reason'])->toContain('attempt limit is invalid');
    Http::assertNothingSent();
})->with([0, 11, '3', null]);

it('keeps missing evidence local instead of spending a provider request', function (): void {
    $task = adviceTask();
    $run = adviceRun($task, report: ['verification' => [], 'review' => ['findings' => []]]);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['next_action'])->toBe('inspect')
        ->and($result['provider']['reason'])->toBe('missing_evidence')
        ->and($result['reason'])->toContain('No usable verification or Tarpit evidence')
        ->and($run->fresh()->report['advice'])->toBe($result);
    Http::assertNothingSent();
});

it('maps TypeSafe choices without changing execution state or treating continue as success', function (string $choice, string $action): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(adviceResponse($choice))]);
    $task = adviceTask();
    $run = adviceRun($task);
    $beforeTask = $task->fresh()->getRawOriginal();
    $beforeRun = $run->fresh()->getRawOriginal();
    $report = $run->report;

    $result = app(RecommendTaskNextStep::class)->handle('health-check');

    expect($result['next_action'])->toBe($action)
        ->and($result['retry_allowed'])->toBeTrue()
        ->and($result['status'])->toBe('evaluated')
        ->and($result['confidence'])->toBe(0.9)
        ->and($result['provider']['answers']['next_action']['choice'])->toBe($choice)
        ->and($result['provider']['answers']['next_action']['probabilities'][$choice])->toBe(1.0)
        ->and($result['provider']['threshold'])->toBe(0.8)
        ->and($result['provider']['duration_ms'])->toBeGreaterThanOrEqual(0)
        ->and($result['fallback'])->toBeFalse()
        ->and($result['persisted'])->toBeTrue()
        ->and(json_encode($run->fresh()->report, JSON_THROW_ON_ERROR))->toBe(json_encode([...$report, 'advice' => $result], JSON_THROW_ON_ERROR))
        ->and($task->fresh()->getRawOriginal())->toBe($beforeTask)
        ->and($run->fresh()->getRawOriginal('updated_at'))->toBe($beforeRun['updated_at'])
        ->and($run->fresh()->status)->toBe('failed')
        ->and($task->runs()->count())->toBe(1);
    Http::assertSentCount(1);
})->with(['retry' => ['retry', 'retry'], 'stop' => ['stop', 'stop'], 'continue' => ['continue', 'inspect'], 'human review' => ['needs_review', 'inspect']]);

it('sends only bounded selected evidence and puts blocking Tarpit findings first', function (): void {
    $payload = str_repeat("\"\\\nroute 🚀", 1000);
    $task = adviceTask();
    $task->update(['prompt' => $payload, 'source' => ['token' => 'source-secret'], 'context_snapshot' => ['source' => 'snapshot-secret']]);
    $findings = [];
    foreach (['warning', 'warning', 'warning', 'blocking'] as $severity) {
        $findings[] = ['code' => 'E', 'severity' => $severity, 'classification' => 'accidental', 'path' => 'routes/web.php', 'line' => 12, 'problem' => $payload, 'recommendation' => $payload, 'extra' => 'finding-secret'];
    }
    $findings[] = ['path' => '.env', 'severity' => 'blocking', 'problem' => 'outside-secret'];
    adviceRun($task, report: ['verification' => ['status' => 'failed', 'tests' => 1, 'assertions' => 4, 'output' => 'output-secret', 'reason' => 'reason-secret'], 'review' => ['checks' => ['E' => ['status' => 'findings', 'evidence' => 'check-secret']], 'findings' => $findings], 'provider' => ['key' => 'provider-secret'], 'source' => 'full-source-secret']);
    $sent = null;
    Http::fake(['https://api.typesafe.ai/v1/systemone' => function ($request) use (&$sent) {
        $sent = $request['state'];

        return Http::response(adviceResponse());
    }]);

    app(RecommendTaskNextStep::class)->handle($task->id);
    $encoded = json_encode($sent, JSON_THROW_ON_ERROR);

    expect(array_keys($sent))->toBe(['prompt', 'verification', 'review'])
        ->and(strlen($encoded))->toBeLessThan(8192)
        ->and($sent['verification'])->toBe(['status' => 'failed', 'tests' => 1, 'assertions' => 4])
        ->and($sent['review']['findings'])->toHaveCount(3)
        ->and($sent['review']['findings'][0]['severity'])->toBe('blocking')
        ->and($encoded)->not->toContain('source-secret', 'snapshot-secret', 'finding-secret', 'outside-secret', 'output-secret', 'reason-secret', 'check-secret', 'provider-secret', 'full-source-secret');
    Http::assertSentCount(1);
});

it('keeps honest deterministic guidance when TypeSafe is disabled or missing a key', function (string $setting, mixed $value, string $reason): void {
    config(['molly.typesafe.'.$setting => $value]);
    $task = adviceTask();
    adviceRun($task);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['status'])->toBe('fallback')
        ->and($result['next_action'])->toBe('inspect')
        ->and($result['fallback'])->toBeTrue()
        ->and($result['confidence'])->toBeNull()
        ->and($result['provider']['reason'])->toBe($reason)
        ->and($result['provider']['answers'])->toBe([]);
    Http::assertNothingSent();
})->with(['disabled' => ['enabled', false, 'disabled'], 'missing key' => ['api_key', '', 'invalid_config']]);

it('keeps failed evidence visible when TypeSafe cannot supply usable advice', function (array $response, int $status, string $reason): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response($response, $status)]);
    $task = adviceTask();
    $run = adviceRun($task);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['next_action'])->toBe('inspect')
        ->and($result['status'])->toBe('fallback')
        ->and($result['provider']['reason'])->toBe($reason)
        ->and($run->fresh()->report['verification']['status'])->toBe('failed');
    Http::assertSentCount(1);
})->with([
    'provider error' => [['error' => 'Do not retain this provider payload.'], 503, 'provider_error'],
    'malformed response' => [['answers' => 'bad response'], 200, 'invalid_response'],
    'low confidence' => [adviceResponse('retry', 0.5), 200, 'low_confidence'],
]);

it('falls back after a provider connection failure without retrying the request', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::failedConnection()]);
    $task = adviceTask();
    adviceRun($task);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['status'])->toBe('fallback')
        ->and($result['provider']['reason'])->toBe('connection_failed')
        ->and($result['next_action'])->toBe('inspect');
});

it('returns current guidance without persisting into a running report', function (): void {
    $task = adviceTask('running');
    $run = adviceRun($task, 'running', ['phase' => 'verification']);
    $before = $run->fresh()->getRawOriginal();

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['persisted'])->toBeFalse()
        ->and($result['next_action'])->toBe('wait')
        ->and($run->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

it('does not persist advice into an attempt with an unrecognized status', function (): void {
    $task = adviceTask();
    $run = adviceRun($task, 'unknown');
    $before = $run->fresh()->getRawOriginal();

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['persisted'])->toBeFalse()
        ->and($result['next_action'])->toBe('inspect')
        ->and($run->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

it('discards a provider answer if the task starts a new attempt during the request', function (): void {
    $task = adviceTask();
    $previous = adviceRun($task);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => function () use ($task) {
        $task->update(['status' => 'running']);
        adviceRun($task, 'running', ['phase' => 'generation']);

        return Http::response(adviceResponse());
    }]);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['next_action'])->toBe('wait')
        ->and($result['retry_allowed'])->toBeFalse()
        ->and($result['status'])->toBe('fallback')
        ->and($result['provider']['reason'])->toBe('evidence_changed')
        ->and($result['persisted'])->toBeFalse()
        ->and($result['observed']['attempt_count'])->toBe(2)
        ->and($previous->fresh()->report)->not->toHaveKey('advice')
        ->and($task->runs()->reorder()->latest()->orderByDesc('id')->first()->report)->not->toHaveKey('advice');
    Http::assertSentCount(1);
});

it('uses the current nickname in a recommended command after a rename during evaluation', function (): void {
    $task = adviceTask();
    adviceRun($task);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => function () use ($task) {
        app(NameTask::class)->handle($task->id, 'renamed-check');

        return Http::response(adviceResponse());
    }]);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['task_id'])->toBe($task->id)
        ->and($result['task_reference'])->toBe('renamed-check')
        ->and($result['command'])->toBe('php artisan molly:retry renamed-check');
});

it('does not append stale advice when run evidence changes just before persistence', function (): void {
    config(['molly.typesafe.enabled' => false]);
    $task = adviceTask();
    $run = adviceRun($task);
    $event = 'eloquent.retrieved: '.Run::class;
    $reads = 0;
    Event::listen($event, function (Run $retrieved) use ($run, &$reads): void {
        if ($retrieved->id === $run->id && ++$reads === 2) {
            DB::table('molly_runs')->where('id', $run->id)->update(['report' => json_encode(['verification' => ['status' => 'failed'], 'later_evidence' => 'Keep this evidence.'], JSON_THROW_ON_ERROR)]);
        }
    });

    try {
        $result = app(RecommendTaskNextStep::class)->handle($task->id);
    } finally {
        Event::forget($event);
    }

    expect($result['persisted'])->toBeFalse()
        ->and($result['provider']['reason'])->toBe('evidence_changed')
        ->and($run->fresh()->report)->toBe(['verification' => ['status' => 'failed'], 'later_evidence' => 'Keep this evidence.']);
});

it('keeps a usable provider recommendation when another advice request finishes first', function (): void {
    $task = adviceTask();
    $run = adviceRun($task);
    $report = $run->report;
    Http::fake(['https://api.typesafe.ai/v1/systemone' => function () use ($task) {
        config(['molly.typesafe.enabled' => false]);
        try {
            $earlier = app(RecommendTaskNextStep::class)->handle($task->id);
            expect($earlier['persisted'])->toBeTrue()->and($earlier['status'])->toBe('fallback');
        } finally {
            config(['molly.typesafe.enabled' => true]);
        }

        return Http::response(adviceResponse());
    }]);

    $result = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($result['status'])->toBe('evaluated')
        ->and($result['next_action'])->toBe('retry')
        ->and($result['fallback'])->toBeFalse()
        ->and($result['persisted'])->toBeTrue()
        ->and(json_encode($run->fresh()->report, JSON_THROW_ON_ERROR))->toBe(json_encode([...$report, 'advice' => $result], JSON_THROW_ON_ERROR))
        ->and($task->fresh()->status)->toBe('failed')
        ->and($task->runs()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('replaces advice saved just before persistence while preserving all other run evidence', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(adviceResponse())]);
    $task = adviceTask();
    $run = adviceRun($task);
    $report = [...$run->report, 'metadata' => ['retain' => 'This metadata is part of the saved report.']];
    $run->update(['report' => $report]);
    $event = 'eloquent.retrieved: '.Run::class;
    $reads = 0;
    Event::listen($event, function (Run $retrieved) use ($run, $report, &$reads): void {
        if ($retrieved->id === $run->id && ++$reads === 2) {
            DB::table('molly_runs')->where('id', $run->id)->update(['report' => json_encode([...$report, 'advice' => ['reason' => 'An earlier advice request finished.']], JSON_THROW_ON_ERROR)]);
        }
    });

    try {
        $result = app(RecommendTaskNextStep::class)->handle($task->id);
    } finally {
        Event::forget($event);
    }

    expect($result['status'])->toBe('evaluated')
        ->and($result['persisted'])->toBeTrue()
        ->and($result['next_action'])->toBe('retry')
        ->and(json_encode($run->fresh()->report, JSON_THROW_ON_ERROR))->toBe(json_encode([...$report, 'advice' => $result], JSON_THROW_ON_ERROR))
        ->and($run->fresh()->status)->toBe('failed');
    Http::assertSentCount(1);
});

it('returns JSON advice and readable terminal guidance without executing the recommendation', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(adviceResponse())]);
    $task = adviceTask();
    $run = adviceRun($task);

    $exit = Artisan::call('molly:advice', ['task' => 'health-check', '--json' => true, '--no-interaction' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['task_id'])->toBe($task->id)
        ->and($result['run_id'])->toBe($run->id)
        ->and($result['command'])->toBe('php artisan molly:retry health-check')
        ->and($task->runs()->count())->toBe(1);

    $this->artisan('molly:advice', ['task' => 'health-check'])
        ->expectsOutputToContain('TypeSafe recommends another bounded attempt.')
        ->expectsOutputToContain('Next command: php artisan molly:retry health-check')
        ->expectsOutputToContain('Advice does not start, retry, or stop a task.')
        ->assertSuccessful();
});

it('reports an unknown task without creating records or calling TypeSafe', function (): void {
    $exit = Artisan::call('molly:advice', ['task' => 'missing', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result)->toBe(['task' => 'missing', 'status' => 'error', 'error' => 'TASK_NOT_FOUND: No saved task has that name or ID.'])
        ->and(Task::count())->toBe(0)
        ->and(Run::count())->toBe(0);
    Http::assertNothingSent();
});

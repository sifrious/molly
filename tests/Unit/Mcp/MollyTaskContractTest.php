<?php

use Sifrious\Molly\Mcp\MollyTask;

it('routes every MollyTask operation through exactly one named dispatch path', function () {
    $operations = (new ReflectionClass(MollyTask::class))->getConstant('OPERATIONS');
    $groups = [
        'dispatchRead' => ['list', 'show', 'show_run'],
        'dispatchCreate' => ['create', 'from_plan', 'import_github'],
        'dispatchLifecycle' => ['comment', 'approve', 'lock_test', 'pr_body', 'pr_opened', 'merged', 'stop'],
        'dispatchHandoff' => ['handoff', 'name', 'link_thread'],
        'dispatchAdvice' => ['advice'],
        'dispatchQueue' => ['start', 'retry'],
    ];

    expect(collect($groups)->flatten()->sort()->values()->all())
        ->toBe(collect($operations)->sort()->values()->all());

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');
    foreach ($groups as $method => $ops) {
        expect($source)->toContain("\$this->{$method}(\$data)");
        foreach ($ops as $operation) {
            expect($source)->toMatch("/'".preg_quote($operation, '/')."'/");
        }
    }
});

it('preserves structured success and RuntimeException error mapping', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');
    expect($source)->toContain('return Response::structured($result);')
        ->and($source)->toContain('return Response::error($exception->getMessage());')
        ->and($source)->toContain('catch (RuntimeException $exception)');
});

it('keeps create start retry stop show lifecycle handoff name thread and advice side-effect boundaries', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');

    expect($source)->toContain('#[IsDestructive]')
        ->and($source)->toContain("app(QueueTask::class)->handle(\$data['id'], \$data['operation'] === 'retry')")
        ->and($source)->toContain("'queued' => true")
        ->and($source)->toContain('private function dispatchQueue(array $data): array')
        ->and($source)->not->toContain('OpenPullRequest')
        ->and($source)->not->toContain('MergePullRequest');

    // Approve-gated lifecycle ops still require the approve flag in validation.
    expect($source)->toContain("'approve' => ['required_if:operation,comment,approve,lock_test,pr_opened,merged', 'boolean']");

    // Create/read paths do not queue work.
    expect($source)->toContain('private function dispatchCreate(array $data): array')
        ->and($source)->toContain('private function dispatchRead(array $data): array')
        ->and($source)->toContain("'display_status' => \$inspection['display_status']")
        ->and($source)->toContain("'linked_pr' => \$inspection['linked_pr']")
        ->and($source)->toContain("'issue_url' => \$inspection['issue_url']");

    // Handoff / name / thread / advice keep named action collaborators.
    expect($source)->toContain('app(HandOffTask::class)')
        ->and($source)->toContain('app(NameTask::class)')
        ->and($source)->toContain('app(LinkTaskThread::class)')
        ->and($source)->toContain('app(RecommendTaskNextStep::class)')
        ->and($source)->toContain('app(StopTask::class)')
        ->and($source)->toContain('app(CreateTask::class)')
        ->and($source)->toContain('app(ShowTask::class)');
});

it('keeps compatible return-shape keys for each MollyTask operation family', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');

    expect($source)->toContain("['tasks' => app(ListTasks::class)")
        ->and($source)->toContain("['run' => (app(ShowRun::class)")
        ->and($source)->toContain("['task' => app(CreateTask::class)")
        ->and($source)->toContain("['task' => app(CreateTaskFromPlan::class)")
        ->and($source)->toContain("['task' => app(ImportGitHubIssue::class)")
        ->and($source)->toContain("['task' => app(QueueTask::class)")
        ->and($source)->toContain("['task' => app(StopTask::class)")
        ->and($source)->toContain("['task' => app(NameTask::class)")
        ->and($source)->toContain("['handoff' => app(HandOffTask::class)")
        ->and($source)->toContain("['association' => app(LinkTaskThread::class)")
        ->and($source)->toContain("['advice' => app(RecommendTaskNextStep::class)");
});

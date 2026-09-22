<?php

use Sifrious\Molly\Actions\ResolveEffectiveRunConfig;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Execution\Sandbox;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\BindWorkspaceReference;

it('injects Sandbox and run config collaborators into RunTask', function () {
    $action = app(RunTask::class);
    $reflection = new ReflectionClass($action);

    foreach ([
        'sandbox' => Sandbox::class,
        'resolveEffectiveRunConfig' => ResolveEffectiveRunConfig::class,
        'bindWorkspaceReference' => BindWorkspaceReference::class,
    ] as $property => $type) {
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        expect($prop->getValue($action))->toBeInstanceOf($type);
    }
});

it('builds the initial run report from one leased task snapshot', function () {
    $action = app(RunTask::class);
    $method = new ReflectionMethod($action, 'initialReport');
    $method->setAccessible(true);

    $root = sys_get_temp_dir().'/molly-run-task-'.uniqid('', true);
    mkdir($root.'/routes', 0700, true);
    file_put_contents($root.'/routes/web.php', "<?php\n");
    $files = new Workspace($root);
    $task = Task::create([
        'nickname' => 'prep-snapshot',
        'prompt' => 'Prepare one identity snapshot.',
        'workspace' => $root,
        'paths' => ['routes/web.php'],
        'test_path' => 'tests/Feature/ExampleTest.php',
        'context_snapshot' => ['settings_overrides' => ['parallel_checks' => false], 'seed' => 'creation-snapshot'],
    ]);

    $report = $method->invoke(
        $action,
        $files,
        ['routes/web.php' => "<?php\n"],
        ['routes/web.php'],
        'tests/Feature/ExampleTest.php',
        'digest-one',
        false,
        $task,
    );

    expect($report['scope'])->toBe(['routes/web.php'])
        ->and($report['protected_test'])->toBe([
            'path' => 'tests/Feature/ExampleTest.php',
            'digest' => 'digest-one',
            'writable' => false,
        ])
        ->and($report['snapshots']['task_creation'])->toBe($task->context_snapshot)
        ->and($report['snapshots']['before']['status'] ?? null)->not->toBe('not_captured')
        ->and($report['snapshots']['after']['status'])->toBe('not_captured')
        ->and($report['components']['status'])->toBe('not_compared');

    $nullReport = $method->invoke(
        $action,
        $files,
        ['routes/web.php' => "<?php\n"],
        ['routes/web.php'],
        'tests/Feature/ExampleTest.php',
        null,
        true,
        null,
    );
    expect($nullReport['snapshots']['task_creation'])->toBeNull()
        ->and($nullReport['protected_test']['writable'])->toBeTrue();
});

it('exposes named success and terminated finalization helpers on RunTask', function () {
    $reflection = new ReflectionClass(RunTask::class);
    expect($reflection->hasMethod('persistSuccessfulCompletion'))->toBeTrue()
        ->and($reflection->hasMethod('persistTerminatedFinalization'))->toBeTrue()
        ->and($reflection->getMethod('persistSuccessfulCompletion')->isPrivate())->toBeTrue()
        ->and($reflection->getMethod('persistTerminatedFinalization')->isPrivate())->toBeTrue();
});

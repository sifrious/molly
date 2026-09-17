<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-snapshots-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/resources/views/components');
    File::put($this->workspace.'/resources/views/components/status.blade.php', '<p>Waiting</p>');
    config([
        'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'session.driver' => 'array',
        'molly.ui.enabled' => true,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function snapshotProposal(): array
{
    return ['summary' => 'Show the completed state.', 'files' => [
        ['path' => 'resources/views/components/status.blade.php', 'content' => '<p>Completed</p>'],
        ['path' => 'tests/StatusTest.php', 'content' => '<?php it("shows the completed state", fn () => expect(file_get_contents(__DIR__."/../resources/views/components/status.blade.php"))->toContain("Completed"));'],
    ]];
}

function snapshotChecks(): void
{
    $checks = [];
    foreach (range('A', 'G') as $key) {
        $checks[$key] = ['status' => 'clean', 'evidence' => 'No finding in the selected files.'];
    }
    TarpitReviewer::fake([['checks' => $checks, 'findings' => []]])->preventStrayPrompts();
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
}

it('freezes selected file hashes at task creation without saving source contents', function () {
    $this->freezeTime();
    $task = app(CreateTask::class)->handle('Show the completed state.', $this->workspace, ['resources/views/components/status.blade.php'], 'tests/StatusTest.php');
    $snapshot = $task->fresh()->context_snapshot;

    expect($snapshot['status'])->toBe('captured')
        ->and($snapshot['captured_at'])->toBe(now()->toIso8601String())
        ->and($snapshot['files'])->toBe([
            ['path' => 'resources/views/components/status.blade.php', 'sha256' => hash('sha256', '<p>Waiting</p>'), 'component' => ['id' => 'component:resources/views/components/status.blade.php', 'kind' => 'blade_component']],
            ['path' => 'tests/StatusTest.php', 'sha256' => null, 'component' => null],
        ])
        ->and($snapshot['preview'])->toBe(['status' => 'unavailable', 'reason' => 'No local preview renderer is configured.'])
        ->and(json_encode($snapshot))->not->toContain('<p>Waiting</p>');

    File::put($this->workspace.'/resources/views/components/status.blade.php', '<p>Changed later</p>');
    $this->travel(1)->minute();
    $later = app(CreateTask::class)->handle('Show the completed state.', $this->workspace, ['resources/views/components/status.blade.php'], 'tests/StatusTest.php');

    expect($task->fresh()->context_snapshot)->toBe($snapshot)
        ->and($later->context_snapshot['files'][0]['sha256'])->toBe(hash('sha256', '<p>Changed later</p>'))
        ->and($later->context_snapshot['files'][0]['component'])->toBe($snapshot['files'][0]['component']);
});

it('assigns stable source IDs to known Blade and Livewire paths in sorted snapshots', function () {
    $workspace = new Workspace($this->workspace);
    $paths = [
        'resources/views/welcome.blade.php',
        'resources/views/livewire/status.blade.php',
        'resources/views/components/status.blade.php',
        'app/View/Components/Status.php',
        'app/Livewire/Status.php',
        'app/Status.php',
    ];
    $before = $workspace->snapshot(array_fill_keys($paths, 'Before'));
    $after = $workspace->snapshot(array_fill_keys(array_reverse($paths), 'After'));

    expect(array_column($before['files'], 'path'))->toBe([
        'app/Livewire/Status.php', 'app/Status.php', 'app/View/Components/Status.php',
        'resources/views/components/status.blade.php', 'resources/views/livewire/status.blade.php', 'resources/views/welcome.blade.php',
    ])
        ->and(array_column($before['files'], 'component'))->toBe([
            ['id' => 'component:app/Livewire/Status.php', 'kind' => 'livewire_class'],
            null,
            ['id' => 'component:app/View/Components/Status.php', 'kind' => 'blade_class'],
            ['id' => 'component:resources/views/components/status.blade.php', 'kind' => 'blade_component'],
            ['id' => 'component:resources/views/livewire/status.blade.php', 'kind' => 'livewire_view'],
            ['id' => 'component:resources/views/welcome.blade.php', 'kind' => 'blade_view'],
        ])
        ->and(array_column($after['files'], 'component'))->toBe(array_column($before['files'], 'component'));
});

it('compares added removed modified and unchanged component sources using observed file changes', function () {
    $workspace = new Workspace($this->workspace);
    $before = [
        'resources/views/components/status.blade.php' => '<p>Waiting</p>',
        'resources/views/components/old.blade.php' => '<p>Old</p>',
        'resources/views/components/new.blade.php' => null,
        'resources/views/components/same.blade.php' => '<p>Same</p>',
        'resources/views/components/absent.blade.php' => null,
        'app/Status.php' => '<?php return false;',
    ];
    $after = [...$before,
        'resources/views/components/status.blade.php' => '<p>Completed</p>',
        'resources/views/components/old.blade.php' => null,
        'resources/views/components/new.blade.php' => '<p>New</p>',
        'app/Status.php' => '<?php return true;',
    ];
    $comparison = $workspace->componentChanges($workspace->changes($before, $after), $before);

    expect(array_column($comparison, 'status', 'path'))->toBe([
        'resources/views/components/new.blade.php' => 'added',
        'resources/views/components/old.blade.php' => 'removed',
        'resources/views/components/same.blade.php' => 'unchanged',
        'resources/views/components/status.blade.php' => 'modified',
    ])
        ->and($comparison[0]['before_hash'])->toBeNull()
        ->and($comparison[0]['after_hash'])->toBe(hash('sha256', '<p>New</p>'))
        ->and($comparison[1]['before_hash'])->toBe(hash('sha256', '<p>Old</p>'))
        ->and($comparison[1]['after_hash'])->toBeNull()
        ->and($comparison[2]['before_hash'])->toBe($comparison[2]['after_hash'])
        ->and($comparison[3]['id'])->toBe('component:resources/views/components/status.blade.php');
});

it('retains task context and run snapshots when later workspace edits occur', function () {
    $task = app(CreateTask::class)->handle('Show the completed state.', $this->workspace, ['resources/views/components/status.blade.php'], 'tests/StatusTest.php');
    $creation = $task->context_snapshot;
    File::put($this->workspace.'/resources/views/components/status.blade.php', '<p>Ready</p>');
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        $report = Run::sole()->report;
        expect($report['snapshots']['before']['files'][0]['sha256'])->toBe(hash('sha256', '<p>Ready</p>'))
            ->and($report['snapshots']['after']['status'])->toBe('not_captured')
            ->and($report['components']['status'])->toBe('not_compared');

        return snapshotProposal();
    });
    snapshotChecks();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);

    $run = app(RunTask::class)->handle($task->prompt, $task->workspace, $task->paths, $task->test_path, taskId: $task->id);
    $report = $run->report;
    File::put($this->workspace.'/resources/views/components/status.blade.php', '<p>Changed after the run</p>');

    expect($run->status)->toBe('completed')
        ->and($report['snapshots']['task_creation'])->toBe($creation)
        ->and($report['snapshots']['after']['files'][0]['sha256'])->toBe(hash('sha256', '<p>Completed</p>'))
        ->and($report['snapshots']['after']['preview']['status'])->toBe('unavailable')
        ->and($report['components']['status'])->toBe('compared')
        ->and($report['components']['changes'][0]['status'])->toBe('modified')
        ->and($task->fresh()->context_snapshot)->toBe($creation)
        ->and($run->fresh()->report)->toBe($report);

    expect(Artisan::call('molly:show', ['run' => $run->id, '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['report'])->toBe($report);
    expect(Artisan::call('molly:show', ['run' => $run->id, '--verbose' => true]))->toBe(0);
    expect(Artisan::output())->toContain('component:resources/views/components/status.blade.php', 'modified', 'No local preview renderer is configured.', hash('sha256', '<p>Waiting</p>'), hash('sha256', '<p>Ready</p>'), hash('sha256', '<p>Completed</p>'));
    $this->get('/molly/runs/'.$run->id)->assertOk()
        ->assertSee('component:resources/views/components/status.blade.php')->assertSee('modified')
        ->assertSee('No local preview renderer is configured.')
        ->assertSee(hash('sha256', '<p>Waiting</p>'))->assertSee(hash('sha256', '<p>Ready</p>'))->assertSee(hash('sha256', '<p>Completed</p>'))
        ->assertDontSee('<p>Waiting</p>', false)->assertDontSee('<p>Changed after the run</p>', false);
});

it('runs older tasks without inventing a task creation snapshot', function () {
    $task = Task::create(['prompt' => 'Show the completed state.', 'workspace' => $this->workspace, 'paths' => ['resources/views/components/status.blade.php'], 'test_path' => 'tests/StatusTest.php']);
    ChangeWriter::fake([snapshotProposal()])->preventStrayPrompts();
    snapshotChecks();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);

    $run = app(RunTask::class)->handle($task->prompt, $task->workspace, $task->paths, $task->test_path, taskId: $task->id);

    expect($run->status)->toBe('completed')
        ->and($task->fresh()->context_snapshot)->toBeNull()
        ->and($run->report['snapshots']['task_creation'])->toBeNull()
        ->and($run->report['snapshots']['before']['status'])->toBe('captured')
        ->and($run->report['components']['changes'][0]['status'])->toBe('modified');
    $this->get('/molly/runs/'.$run->id)->assertOk()->assertSee('The original task context is unknown.');
    Artisan::call('molly:show', ['run' => $run->id]);
    expect(Artisan::output())->toContain('No task-creation snapshot is available. The original task context is unknown.');
});

it('reports unchanged selected components when only their test changes', function () {
    $proposal = snapshotProposal();
    $proposal['files'] = [$proposal['files'][1]];
    ChangeWriter::fake([$proposal])->preventStrayPrompts();
    snapshotChecks();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);

    $run = app(RunTask::class)->handle('Add a test for the current status.', $this->workspace, ['resources/views/components/status.blade.php'], 'tests/StatusTest.php');

    expect($run->status)->toBe('completed')
        ->and($run->report['components']['changes'])->toHaveCount(1)
        ->and($run->report['components']['changes'][0]['status'])->toBe('unchanged')
        ->and($run->report['components']['changes'][0]['before_hash'])->toBe(hash('sha256', '<p>Waiting</p>'))
        ->and($run->report['components']['changes'][0]['after_hash'])->toBe(hash('sha256', '<p>Waiting</p>'));
    $this->get('/molly/runs/'.$run->id)->assertOk()->assertSee('unchanged');
    Artisan::call('molly:show', ['run' => $run->id]);
    expect(Artisan::output())->toContain('unchanged');
});

it('does not describe missing historical snapshots as unchanged components', function () {
    $run = Run::create(['prompt' => 'A historical run.', 'workspace' => $this->workspace, 'status' => 'completed', 'report' => []]);

    $this->get('/molly/runs/'.$run->id)->assertOk()->assertSee('Component snapshots were not recorded for this run.')->assertSee('Component changes were not recorded.')->assertDontSee('No selected components changed.');
    Artisan::call('molly:show', ['run' => $run->id]);
    expect(Artisan::output())->toContain('Component snapshots were not recorded for this run.')
        ->not->toContain('unchanged', 'No selected components changed.');
});

it('reports unknown final components when the final workspace capture fails', function () {
    ChangeWriter::fake([snapshotProposal()])->preventStrayPrompts();
    snapshotChecks();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        File::delete($this->workspace.'/resources/views/components/status.blade.php');
        File::makeDirectory($this->workspace.'/resources/views/components/status.blade.php');

        return ['status' => 'passed', 'tests' => 1, 'assertions' => 1];
    });

    $run = app(RunTask::class)->handle('Show the completed state.', $this->workspace, ['resources/views/components/status.blade.php'], 'tests/StatusTest.php');

    expect($run->status)->toBe('failed')
        ->and($run->report['snapshots']['before']['status'])->toBe('captured')
        ->and($run->report['snapshots']['after']['status'])->toBe('unavailable')
        ->and($run->report['snapshots']['after']['reason'])->toStartWith('SNAPSHOT_CAPTURE_FAILED: PATH_INVALID:')
        ->and($run->report['snapshots']['after'])->not->toHaveKey('files')
        ->and($run->report['components']['status'])->toBe('unavailable')
        ->and($run->report['components'])->not->toHaveKey('changes')
        ->and($run->report['changes_unavailable'])->toBeTrue();
    $this->get('/molly/runs/'.$run->id)->assertOk()->assertSee('Component changes are unknown.')->assertSee('SNAPSHOT_CAPTURE_FAILED')->assertDontSee('No selected components changed.');
    Artisan::call('molly:show', ['run' => $run->id]);
    expect(Artisan::output())->toContain('Component changes are unknown.', 'SNAPSHOT_CAPTURE_FAILED')->not->toContain('unchanged');
});

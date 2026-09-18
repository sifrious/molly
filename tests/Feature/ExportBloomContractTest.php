<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ExportBloomContract;
use Sifrious\Molly\Contracts\TaskContract;

it('exports a versioned task contract Bloom can bind to an existing workspace', function () {
    $workspace = sys_get_temp_dir().'/molly-bloom-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($workspace);
    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $bloomWorkspaceId = (string) Str::uuid();
    $baseSha = str_repeat('a', 40);

    $contract = app(ExportBloomContract::class)->handle($task->id, $bloomWorkspaceId, 'molly/greeting', $baseSha);

    expect($contract->taskId)->toBe($task->id)
        ->and($contract->bloomWorkspaceId)->toBe($bloomWorkspaceId)
        ->and($contract->workspacePath)->toBe(realpath($workspace))
        ->and($contract->branch)->toBe('molly/greeting')
        ->and($contract->allowedWritePaths)->toBe(['app/Greeting.php'])
        ->and($contract->protectedPaths)->toBe(['tests/GreetingTest.php'])
        ->and($contract->approval->beforePullRequest)->toBeTrue()
        ->and($contract->approval->beforeMerge)->toBeTrue()
        ->and($contract->toArray()['schema'])->toBe(TaskContract::SCHEMA);

    $exit = Artisan::call('molly:bloom-contract', [
        'task' => $task->id,
        '--workspace-id' => $bloomWorkspaceId,
        '--branch' => 'molly/greeting',
        '--base-sha' => $baseSha,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['bloom_workspace_id'])->toBe($bloomWorkspaceId)
        ->and($payload['protected_paths'])->toBe(['tests/GreetingTest.php']);

    File::deleteDirectory($workspace);
});

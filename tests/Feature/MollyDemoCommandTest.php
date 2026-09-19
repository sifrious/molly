<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Console\MollyDemoCommand;
use Sifrious\Molly\Models\Task;

it('scaffolds the greeting demo and saves a reusable demo-greeting task', function () {
    $workspace = sys_get_temp_dir().'/molly-demo-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::ensureDirectoryExists($workspace.'/tests/Feature');
    File::put($workspace.'/.gitignore', "/vendor/\n");

    $exit = Artisan::call('molly:demo', [
        '--workspace' => $workspace,
        '--json' => true,
        '--no-interaction' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('ready')
        ->and($payload['task'])->toBe(MollyDemoCommand::TASK_NAME)
        ->and($payload['prompt'])->toBe(MollyDemoCommand::PROMPT)
        ->and($payload['allowed_write_paths'])->toBe([MollyDemoCommand::IMPLEMENTATION_PATH])
        ->and($payload['protected_paths'])->toBe([MollyDemoCommand::TEST_PATH])
        ->and($payload['created']['greeting'])->toBeTrue()
        ->and($payload['created']['test'])->toBeTrue()
        ->and($payload['created']['task'])->toBeTrue()
        ->and(File::exists($workspace.'/app/Greeting.php'))->toBeTrue()
        ->and(File::exists($workspace.'/tests/Feature/GreetingTest.php'))->toBeTrue()
        ->and(File::get($workspace.'/.gitignore'))->toContain('.molly/')
        ->and(Task::where('nickname', MollyDemoCommand::TASK_NAME)->count())->toBe(1);

    $task = Task::where('nickname', MollyDemoCommand::TASK_NAME)->first();
    expect($task->paths)->toBe([MollyDemoCommand::IMPLEMENTATION_PATH])
        ->and($task->test_path)->toBe(MollyDemoCommand::TEST_PATH)
        ->and($task->allow_test_edits)->toBeFalse()
        ->and(is_string($task->test_digest) && $task->test_digest !== '')->toBeTrue()
        ->and($task->test_digest)->toBe(hash('sha256', File::get($workspace.'/tests/Feature/GreetingTest.php')));

    $second = Artisan::call('molly:demo', [
        '--workspace' => $workspace,
        '--json' => true,
        '--no-interaction' => true,
    ]);
    $again = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($second)->toBe(0)
        ->and($again['created']['greeting'])->toBeFalse()
        ->and($again['created']['test'])->toBeFalse()
        ->and($again['created']['task'])->toBeFalse()
        ->and(Task::where('nickname', MollyDemoCommand::TASK_NAME)->count())->toBe(1);

    File::deleteDirectory($workspace);
});

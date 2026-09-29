<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/molly-queue-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Hello.php', '<?php');
    writeProtectedTest($this->workspace, 'tests/Hello.php');
    commitGitWorkspace($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('names the queue setting molly:queue refuses with a code in text and JSON', function (string $driver, int $retryAfter, string $code, string $detail): void {
    config(['queue.default' => 'molly-queue-test', 'queue.connections.molly-queue-test' => ['driver' => $driver, 'retry_after' => $retryAfter]]);
    Queue::fake();
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/Hello.php', nickname: 'queued');

    $exit = Artisan::call('molly:queue', ['task' => 'queued', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(1)
        ->and($result)->toMatchArray(['status' => 'error', 'task' => 'queued'])
        ->and($result['error'])->toStartWith($code.': ')->toContain('molly-queue-test queue connection', $detail);

    expect(Artisan::call('molly:queue', ['task' => 'queued']))->toBe(1)
        ->and(Artisan::output())->toContain($code.': ', $detail);
    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe('pending');
})->with([
    'sync driver' => ['sync', 3700, 'QUEUE_DRIVER_UNSUPPORTED', 'uses the sync driver'],
    'short retry_after' => ['database', 90, 'QUEUE_RETRY_AFTER_TOO_SHORT', 'It is 90.'],
]);

it('prints the queue refusal code on stderr instead of COMMAND_FAILED', function (): void {
    $database = $this->workspace.'/database.sqlite';
    touch($database);
    $environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_RETRY_AFTER' => '90'];
    testbenchProcess(['migrate', '--force'], $environment)->mustRun();
    testbenchProcess(['molly:create', 'Return Hello.', '--workspace='.$this->workspace, '--file=app/Hello.php', '--test=tests/Hello.php', '--name=queued', '--json', '--no-interaction'], $environment)->mustRun();

    $process = testbenchProcess(['molly:queue', 'queued', '--json', '--no-interaction'], $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and(trim($process->getErrorOutput()))->toStartWith('QUEUE_RETRY_AFTER_TOO_SHORT: Set the database queue connection retry_after above 3600 seconds')
        ->and($process->getErrorOutput())->not->toContain('COMMAND_FAILED');
});

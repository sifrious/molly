<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

function runQueuedExecutionWorkers(string $root, int $count): void
{
    $workers = [];
    try {
        for ($index = 0; $index < $count; $index++) {
            $workers[] = Process::path($root)->timeout(30)->start([PHP_BINARY, $root.'/artisan', 'queue:work', 'database', '--once', '--tries=1', '--sleep=0', '--no-interaction']);
        }
        foreach ($workers as $worker) {
            $result = $worker->wait();
            expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
        }
    } finally {
        foreach ($workers as $worker) {
            $worker->stop(0.2);
        }
        foreach (glob($root.'/storage/molly/*/*.group') as $marker) {
            $pid = (int) file_get_contents($marker);
            if ($pid > 1) {
                @posix_kill(-$pid, 9);
            }
        }
    }
}

it('executes duplicate database jobs in competing workers with exactly one writing attempt', function (): void {
    if (PHP_VERSION_ID < 80400) {
        $this->markTestSkipped('Competing SQLite workers require PHP 8.4 or later. Laravel ignores transaction_mode on PHP 8.3.');
    }

    $root = queuedExecutionFixture(duplicate: true);
    try {
        runQueuedExecutionWorkers($root, 2);
        $state = queuedExecutionState($root);

        expect(count(glob($root.'/reserved-*')))->toBe(2, json_encode($state));
        expect($state['task']['status'])->toBe('completed', json_encode($state));
        expect($state['runs'])->toHaveCount(1);
        expect($state['runs'][0]['status'])->toBe('completed');
        expect($state['runs'][0]['report']['verification']['tests'])->toBe(1);
        expect($state['runs'][0]['report']['verification']['assertions'])->toBe(1);
        expect(array_column($state['runs'][0]['report']['branches'], 'status'))->toBe(['passed', 'passed']);
        expect(file($root.'/generation-calls', FILE_IGNORE_NEW_LINES))->toHaveCount(1);
        expect(file($root.'/review-calls', FILE_IGNORE_NEW_LINES))->toHaveCount(1);
        expect(trim(file_get_contents($root.'/review-calls')))->not->toBe(trim(file_get_contents($root.'/generation-calls')));
        expect(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn true;\n");
        expect($state['jobs'])->toBe(0);
        expect($state['failed'])->toHaveCount(1);
        expect($state['failed'][0]['exception'])->toContain('WORKSPACE_BUSY');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('persists failed real Pest verification after a database worker finishes', function (): void {
    $root = queuedExecutionFixture(fail: true);
    try {
        runQueuedExecutionWorkers($root, 1);
        $state = queuedExecutionState($root);

        expect($state['task']['status'])->toBe('failed', json_encode($state));
        expect($state['runs'])->toHaveCount(1);
        expect($state['runs'][0]['status'])->toBe('failed');
        expect($state['runs'][0]['report']['verification']['failures'])->toBe(1);
        expect($state['runs'][0]['report']['verification']['reason'])->toBe('tests_failed');
        expect(array_column($state['runs'][0]['report']['branches'], 'status'))->toBe(['failed', 'passed']);
        expect($state['jobs'])->toBe(0);
        expect($state['failed'])->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

it('does not generate or edit files when a queued task is stopped before its worker starts', function (): void {
    $root = queuedExecutionFixture(stop: true);
    try {
        runQueuedExecutionWorkers($root, 1);
        $state = queuedExecutionState($root);

        expect($state['task']['status'])->toBe('stopped');
        expect($state['runs'])->toBe([]);
        expect(file_exists($root.'/generation-calls'))->toBeFalse();
        expect(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn false;\n");
        expect($state['jobs'])->toBe(0);
        expect($state['failed'])->toHaveCount(1);
        expect($state['failed'][0]['exception'])->toContain('TASK_NOT_PENDING');
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

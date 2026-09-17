<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Sifrious\Molly\Actions\ReadAmpConnections;

function ampThreadId(int $number = 1): string
{
    return 'T-00000000-0000-0000-0000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
}

function ampSnapshot(array $threads = [], bool $reconnecting = false): string
{
    return json_encode(['updatedAt' => now()->toIso8601String(), 'reconnecting' => $reconnecting, 'threads' => $threads]);
}

function fakeAmpConnections(array $snapshots, array $exports = [], int $exitCode = 0, string $errors = ''): void
{
    Sleep::fake();
    Process::preventStrayProcesses();
    $fakes = [
        "'amp' '--version'" => Process::describe()->output('0.0.1788782430-g6feb0e (released 2026-09-07)'),
        "'amp' 'top' '--stream-jsonl'" => Process::describe()->output($snapshots)->errorOutput($errors)->exitCode($exitCode),
    ];
    foreach ($exports as $id => $export) {
        $fakes["'amp' 'threads' 'export' '$id'"] = Process::describe()->output(is_array($export) ? json_encode($export) : $export);
    }
    Process::fake($fakes);
}

it('reads only requested connection fields and raw executor types without exposing thread content', function () {
    $this->freezeTime();
    $id = ampThreadId();
    fakeAmpConnections([ampSnapshot([
        ['id' => $id, 'executorConnected' => true, 'working' => false, 'title' => 'Private title', 'status' => '7h'],
        ['id' => ampThreadId(2), 'executorConnected' => false, 'working' => true, 'title' => 'Unrelated title'],
    ])], [$id => ['id' => $id, 'meta' => ['executorType' => 'future-runtime'], 'messages' => [['content' => 'Private message']]]]);

    $report = app(ReadAmpConnections::class)->handle([$id]);

    expect($report)->toBe([
        'status' => 'observed', 'reason' => null, 'provider_version' => '0.0.1788782430-g6feb0e',
        'observed_at' => now()->toIso8601String(),
        'threads' => [[
            'thread_id' => $id, 'status' => 'observed', 'reason' => null,
            'executor_connected' => true, 'working' => false, 'executor_type' => 'future-runtime',
        ]],
    ]);
    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array($process->command, [
        ['amp', '--version'], ['amp', 'top', '--stream-jsonl'], ['amp', 'threads', 'export', $id],
    ], true) && $process->timeout <= 3, 3);
});

it('waits past the initial empty snapshot and deduplicates canonical thread IDs', function () {
    $id = 'T-abcdefab-cdef-abcd-efab-cdefabcdefab';
    fakeAmpConnections([ampSnapshot(), ampSnapshot([['id' => $id, 'executorConnected' => false, 'working' => false]])], [
        $id => ['id' => $id, 'meta' => ['executorType' => 'sandbox']],
    ]);

    $report = app(ReadAmpConnections::class)->handle([$id, ' '.strtolower($id).' ', strtoupper($id)]);

    expect($report['threads'])->toHaveCount(1)
        ->and($report['threads'][0]['thread_id'])->toBe('T-abcdefab-cdef-abcd-efab-cdefabcdefab')
        ->and($report['threads'][0]['executor_connected'])->toBeFalse()
        ->and($report['threads'][0]['working'])->toBeFalse()
        ->and($report['threads'][0]['executor_type'])->toBe('sandbox');
    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === ['amp', 'threads', 'export', $id], 1);
});

it('keeps missing threads unknown even when the stream is authenticated and empty', function () {
    $id = ampThreadId();
    fakeAmpConnections([ampSnapshot()], [$id => ['id' => $id, 'meta' => ['executorType' => 'sandbox']]]);

    $report = app(ReadAmpConnections::class)->handle([$id]);

    expect($report['status'])->toBe('unknown')
        ->and($report['threads'][0]['executor_connected'])->toBeNull()
        ->and($report['threads'][0]['working'])->toBeNull()
        ->and($report['threads'][0]['executor_type'])->toBe('sandbox');
});

it('invalidates earlier observations when the latest snapshot reconnects or omits a thread', function (bool $reconnecting) {
    $id = ampThreadId();
    fakeAmpConnections([
        ampSnapshot([['id' => $id, 'executorConnected' => true, 'working' => true]]),
        ampSnapshot([], $reconnecting),
    ], [$id => ['id' => $id]]);

    $report = app(ReadAmpConnections::class)->handle([$id]);

    expect($report['status'])->toBe('unknown')
        ->and($report['threads'][0]['executor_connected'])->toBeNull()
        ->and($report['threads'][0]['working'])->toBeNull();
    if ($reconnecting) {
        expect($report['reason'])->toStartWith('AMP_RECONNECTING:');
    }
})->with([true, false]);

it('rejects invalid IDs before starting a process', function (mixed $id) {
    Process::fake();

    expect(fn () => app(ReadAmpConnections::class)->handle([$id]))->toThrow(RuntimeException::class, 'AMP_THREAD_INVALID:');
    Process::assertNothingRan();
})->with(['--help', 'T-123', 'https://ampcode.com/threads/'.ampThreadId(), ampThreadId().';touch /tmp/unexpected', 42, null, ['nested']]);

it('limits the number of requested threads before starting a process', function () {
    Process::fake();

    expect(fn () => app(ReadAmpConnections::class)->handle(array_map(ampThreadId(...), range(1, 21))))
        ->toThrow(RuntimeException::class, 'AMP_THREAD_LIMIT:');
    Process::assertNothingRan();
});

it('does not read the account when no threads were requested', function () {
    Process::fake();

    $report = app(ReadAmpConnections::class)->handle([]);

    expect($report['status'])->toBe('unknown')->and($report['threads'])->toBe([]);
    Process::assertNothingRan();
});

it('reports invalid snapshots without retaining earlier connection evidence', function (string $invalid) {
    $id = ampThreadId();
    fakeAmpConnections([ampSnapshot([['id' => $id, 'executorConnected' => false, 'working' => false]]), $invalid]);

    $report = app(ReadAmpConnections::class)->handle([$id]);

    expect($report['status'])->toBe('unavailable')
        ->and($report['reason'])->toStartWith('AMP_RESPONSE_INVALID:')
        ->and($report['threads'][0]['executor_connected'])->toBeNull();
})->with(['not JSON', '{"threads":[]}', '[]', '{"threads":[],"updatedAt":"2026-09-17T12:00:00Z","reconnecting":"false"}']);

it('rejects old snapshots instead of showing stale connection state', function () {
    $this->freezeTime();
    fakeAmpConnections([json_encode([
        'updatedAt' => now()->subMinute()->toIso8601String(), 'reconnecting' => false,
        'threads' => [['id' => ampThreadId(), 'executorConnected' => false, 'working' => false]],
    ])]);

    $report = app(ReadAmpConnections::class)->handle([ampThreadId()]);

    expect($report['reason'])->toStartWith('AMP_SNAPSHOT_STALE:')
        ->and($report['threads'][0]['executor_connected'])->toBeNull();
});

it('requires boolean connection fields and unique requested thread entries', function (array $threads) {
    fakeAmpConnections([ampSnapshot($threads)]);

    $report = app(ReadAmpConnections::class)->handle([ampThreadId()]);

    expect($report['reason'])->toStartWith('AMP_RESPONSE_INVALID:')
        ->and($report['threads'][0]['executor_connected'])->toBeNull();
})->with([
    [[['id' => ampThreadId(), 'executorConnected' => 'false', 'working' => false]]],
    [[['id' => ampThreadId(), 'executorConnected' => false]]],
    [[['id' => ampThreadId(), 'executorConnected' => true, 'working' => false], ['id' => ampThreadId(), 'executorConnected' => false, 'working' => false]]],
]);

it('does not expose authentication errors or retain untrusted snapshot output after failure', function () {
    fakeAmpConnections([ampSnapshot([['id' => ampThreadId(), 'executorConnected' => false, 'working' => false]])], [], 1, 'Unauthorized: private credential');

    $report = app(ReadAmpConnections::class)->handle([ampThreadId()]);

    expect($report['reason'])->toStartWith('AMP_AUTHENTICATION_FAILED:')
        ->and(json_encode($report))->not->toContain('private credential')
        ->and($report['threads'][0]['executor_connected'])->toBeNull();
});

it('reports a missing executable without exposing process errors', function () {
    Process::fake(["'amp' '--version'" => Process::describe()->errorOutput('private path')->exitCode(127)]);

    $report = app(ReadAmpConnections::class)->handle([ampThreadId()]);

    expect($report['reason'])->toStartWith('AMP_BINARY_UNAVAILABLE:')
        ->and(json_encode($report))->not->toContain('private path');
});

it('rejects a malformed version before reading account metadata', function () {
    Process::fake(["'amp' '--version'" => Process::describe()->output('unexpected output')]);

    $report = app(ReadAmpConnections::class)->handle([ampThreadId()]);

    expect($report['reason'])->toStartWith('AMP_VERSION_INVALID:');
    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === ['amp', '--version'], 1);
});

it('keeps observed connection state when executor metadata is unavailable or belongs to another thread', function (mixed $export) {
    $id = ampThreadId();
    fakeAmpConnections([ampSnapshot([['id' => $id, 'executorConnected' => true, 'working' => true]])], [$id => $export]);

    $report = app(ReadAmpConnections::class)->handle([$id]);

    expect($report['threads'][0]['executor_connected'])->toBeTrue()
        ->and($report['threads'][0]['executor_type'])->toBeNull()
        ->and($report['threads'][0]['reason'])->toStartWith('AMP_METADATA_UNAVAILABLE:');
})->with([
    'invalid JSON',
    'wrong thread' => [['id' => ampThreadId(2), 'meta' => ['executorType' => 'sandbox']]],
    'missing type' => [['id' => ampThreadId()]],
    'private prose' => [['id' => ampThreadId(), 'meta' => ['executorType' => 'some private prose']]],
]);

it('rejects oversized output without exposing the output', function () {
    fakeAmpConnections([str_repeat('private text', 100000)]);

    $report = app(ReadAmpConnections::class)->handle([ampThreadId()]);

    expect($report['reason'])->toStartWith('AMP_OUTPUT_LIMIT:')
        ->and(json_encode($report))->not->toContain('private text');
});

it('keeps missing threads unknown alongside observed threads', function () {
    $id = ampThreadId();
    $missing = ampThreadId(2);
    fakeAmpConnections([ampSnapshot([['id' => $id, 'executorConnected' => false, 'working' => false]])], [
        $id => ['id' => $id], $missing => ['id' => $missing],
    ]);

    $report = app(ReadAmpConnections::class)->handle([$missing, $id]);

    expect($report['status'])->toBe('observed')
        ->and($report['reason'])->toStartWith('AMP_PARTIAL:')
        ->and(array_column($report['threads'], 'thread_id'))->toBe([$missing, $id])
        ->and($report['threads'][0]['executor_connected'])->toBeNull()
        ->and($report['threads'][1]['executor_connected'])->toBeFalse();
});

it('discards oversized exports while retaining valid connection evidence', function () {
    $id = ampThreadId();
    fakeAmpConnections([ampSnapshot([['id' => $id, 'executorConnected' => true, 'working' => false]])], [
        $id => str_repeat('private', 800000),
    ]);

    $report = app(ReadAmpConnections::class)->handle([$id]);

    expect($report['threads'][0]['executor_connected'])->toBeTrue()
        ->and($report['threads'][0]['executor_type'])->toBeNull()
        ->and($report['threads'][0]['reason'])->toStartWith('AMP_METADATA_UNAVAILABLE:')
        ->and(json_encode($report))->not->toContain('private');
});

it('stops a process that exceeds the output limit before waiting for its timeout', function () {
    $binary = tempnam(sys_get_temp_dir(), 'molly-amp-');
    symlink(PHP_BINARY, $binary.'-php');
    file_put_contents($binary, '#!'.$binary.'-php'."\n<?php\nif (\$argv[1] === '--version') { echo '0.0.1788782430-g6feb0e'; exit; }\necho str_repeat('x', 2097152); sleep(20);\n");
    chmod($binary, 0700);
    $start = microtime(true);

    try {
        $report = (new ReadAmpConnections($binary))->handle([ampThreadId()]);

        expect(microtime(true) - $start)->toBeLessThan(2)
            ->and($report['reason'])->toStartWith('AMP_OUTPUT_LIMIT:')
            ->and($report['threads'][0]['executor_connected'])->toBeNull();
    } finally {
        unlink($binary);
        unlink($binary.'-php');
    }
});

it('rejects a partial final snapshot instead of keeping earlier connection evidence', function () {
    $binary = tempnam(sys_get_temp_dir(), 'molly-amp-');
    symlink(PHP_BINARY, $binary.'-php');
    $payload = ampSnapshot([['id' => ampThreadId(), 'executorConnected' => true, 'working' => true]])."\n".'{"reconnecting":';
    file_put_contents($binary, '#!'.$binary.'-php'."\n<?php\nif (\$argv[1] === '--version') { echo '0.0.1788782430-g6feb0e'; exit; }\necho ".var_export($payload, true).';');
    chmod($binary, 0700);

    try {
        $report = (new ReadAmpConnections($binary))->handle([ampThreadId()]);

        expect($report['reason'])->toStartWith('AMP_RESPONSE_INVALID:')
            ->and($report['threads'][0]['executor_connected'])->toBeNull();
    } finally {
        unlink($binary);
        unlink($binary.'-php');
    }
});

it('ends a silent live stream within the read timeout and leaves connection state unknown', function () {
    $binary = tempnam(sys_get_temp_dir(), 'molly-amp-');
    symlink(PHP_BINARY, $binary.'-php');
    file_put_contents($binary, '#!'.$binary.'-php'."\n<?php\nif (\$argv[1] === '--version') { echo '0.0.1788782430-g6feb0e'; exit; }\nif (\$argv[1] === 'top') { sleep(20); }\n");
    chmod($binary, 0700);
    $start = microtime(true);

    try {
        $report = (new ReadAmpConnections($binary))->handle([ampThreadId()]);

        expect(microtime(true) - $start)->toBeLessThan(5)
            ->and($report['reason'])->toStartWith('AMP_TIMEOUT:')
            ->and($report['threads'][0]['executor_connected'])->toBeNull();
    } finally {
        unlink($binary);
        unlink($binary.'-php');
    }
});

it('retains explicit connection evidence when Molly ends a healthy long running observation stream', function () {
    $binary = tempnam(sys_get_temp_dir(), 'molly-amp-');
    symlink(PHP_BINARY, $binary.'-php');
    $payload = ampSnapshot()."\n".ampSnapshot([['id' => ampThreadId(), 'executorConnected' => false, 'working' => false]])."\n";
    file_put_contents($binary, '#!'.$binary.'-php'."\n<?php\nif (\$argv[1] === '--version') { echo '0.0.1788782430-g6feb0e'; exit; }\nif (\$argv[1] === 'top') { echo ".var_export($payload, true).'; sleep(20); }');
    chmod($binary, 0700);

    try {
        $report = (new ReadAmpConnections($binary))->handle([ampThreadId()]);

        expect($report['status'])->toBe('observed')
            ->and($report['threads'][0]['executor_connected'])->toBeFalse()
            ->and($report['threads'][0]['working'])->toBeFalse();
    } finally {
        unlink($binary);
        unlink($binary.'-php');
    }
});

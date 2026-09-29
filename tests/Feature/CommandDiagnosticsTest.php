<?php

use Symfony\Component\Process\Process;

/** Run a Molly command as its own process through Testbench, with a migrated SQLite database. */
function artisanProcess(array $arguments): Process
{
    static $database = null;
    if ($database === null) {
        $database = sys_get_temp_dir().'/molly-diagnostics-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($database);
        register_shutdown_function(fn () => @unlink($database));
        testbenchProcess(['migrate', '--force'], ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database])->mustRun();
    }

    $process = testbenchProcess([...$arguments, '--no-interaction'], ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]);
    $process->run();

    return $process;
}

it('keeps the JSON document on stdout and writes one coded line to stderr', function (array $arguments, string $code): void {
    $process = artisanProcess([...$arguments, '--json']);
    $document = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $stderr = trim($process->getErrorOutput());

    expect($process->getExitCode())->toBe(1)
        ->and($document)->toBeArray()
        ->and($stderr)->toStartWith($code.': ')
        ->and(substr_count($stderr, "\n"))->toBe(0)
        ->and(json_encode($document))->toContain($code);
})->with([
    'unknown task' => [['molly:task', 'nope'], 'TASK_NOT_FOUND'],
    'unknown worker action' => [['molly:worker', 'pause'], 'WORKER_ACTION_INVALID'],
    'unknown run' => [['molly:show', 'not-a-run'], 'RUN_NOT_FOUND'],
    'unknown option' => [['molly:tasks', '--bogus'], 'ARGUMENTS_INVALID'],
    'missing argument' => [['molly:advice'], 'ARGUMENTS_INVALID'],
    'missing doctor workspace' => [['molly:doctor', '--workspace=/nonexistent/molly-path'], 'WORKSPACE_INVALID'],
    'clever:scan unknown option' => [['clever:scan', '--bogus-option'], 'ARGUMENTS_INVALID'],
    'clever:hotspots unknown option' => [['clever:hotspots', '--bogus-option'], 'ARGUMENTS_INVALID'],
]);

it('writes human-readable errors to stderr and nothing to stdout', function (array $arguments, string $code): void {
    $process = artisanProcess($arguments);

    expect($process->getExitCode())->toBe(1)
        ->and(trim($process->getOutput()))->toBe('')
        ->and($process->getErrorOutput())->toContain($code);
})->with([
    'unknown task' => [['molly:task', 'nope'], 'TASK_NOT_FOUND'],
    'unknown option' => [['molly:task', 'nope', '--bogus'], 'ARGUMENTS_INVALID'],
    'missing doctor workspace' => [['molly:doctor', '--workspace=/nonexistent/molly-path'], 'WORKSPACE_INVALID: /nonexistent/molly-path'],
    'clever:hotspots unknown option' => [['clever:hotspots', '--bogus-option'], 'ARGUMENTS_INVALID'],
    'clever:lonely-files unknown option' => [['clever:lonely-files', '--bogus-option'], 'ARGUMENTS_INVALID'],
    'clever:owned-diff unknown option' => [['clever:owned-diff', '--bogus-option'], 'ARGUMENTS_INVALID'],
    'clever:scan unknown option' => [['clever:scan', '--bogus-option'], 'ARGUMENTS_INVALID'],
    'clever:welds unknown option' => [['clever:welds', '--bogus-option'], 'ARGUMENTS_INVALID'],
]);

it('names the missing workspace in molly:doctor instead of reporting pest_missing', function (): void {
    $process = artisanProcess(['molly:doctor', '--workspace=/nonexistent/molly-path', '--json']);
    $document = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(1)
        ->and($document['ready'])->toBeFalse()
        ->and($document['error'])->toStartWith('WORKSPACE_INVALID: /nonexistent/molly-path is not an existing directory.')
        ->and($document)->not->toHaveKey('checks')
        ->and(trim($process->getErrorOutput()))->toBe($document['error']);
});

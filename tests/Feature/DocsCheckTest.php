<?php

use Symfony\Component\Process\Process;

function docsCheck(string ...$arguments): Process
{
    $process = new Process(['python3', dirname(__DIR__, 2).'/bin/molly-docs-check', ...$arguments]);
    $process->run();

    return $process;
}

it('passes the documentation check with the Orb tutorial required and no Orb requirement open', function () {
    $check = docsCheck();
    $source = file_get_contents(dirname(__DIR__, 2).'/bin/molly-docs-check');

    expect($check->getExitCode())->toBe(0, $check->getOutput().$check->getErrorOutput())
        ->and($check->getOutput())->toContain('required alpha docs.')
        ->and($check->getOutput())->not->toContain('open alpha requirement: Local and Orb execution targeting')
        ->and($source)->toContain("'Local and Orb execution targeting': 'docs/execution-targets.md#run-tasks-on-two-local-orbs'");
});

it('blocks a release exactly when an alpha requirement is open', function () {
    $open = substr_count(docsCheck()->getOutput(), 'open alpha requirement:');
    $release = docsCheck('--release');

    expect($release->getExitCode())->toBe($open > 0 ? 1 : 0, $release->getOutput())
        ->and($release->getOutput())->toContain($open > 0 ? "Release blocked: {$open} alpha requirement(s) open." : 'required alpha docs.');
});

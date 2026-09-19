<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('refuses a non-empty target directory without --force', function () {
    $script = dirname(__DIR__, 2).'/bin/molly-demo';
    expect(is_file($script))->toBeTrue();

    $dir = sys_get_temp_dir().'/molly-demo-download-'.Str::uuid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/marker.txt', 'keep');

    $process = new Process(['bash', $script, $dir]);
    $process->setTimeout(30);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Refusing')
        ->and(File::exists($dir.'/marker.txt'))->toBeTrue();

    File::deleteDirectory($dir);
});

it('prints help without creating a project', function () {
    $script = dirname(__DIR__, 2).'/bin/molly-demo';
    $process = new Process(['bash', $script, '--help']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Usage: molly-demo');
});

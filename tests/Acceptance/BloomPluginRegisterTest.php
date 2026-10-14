<?php

/*
 * bin/molly-bloom-plugin-register refuses a MollySurfaces dylib that dyld
 * cannot load. These tests stub otool and dwarfdump so the check runs
 * without a Mac.
 */

use Symfony\Component\Process\Process;

it('refuses a Surfaces bundle whose MollySurfaces has no LC_UUID', function () {
    $root = dirname(__DIR__, 2);
    $scratch = sys_get_temp_dir().'/molly-register-uuid-'.bin2hex(random_bytes(4));
    $bin = $scratch.'/bin';
    $app = $scratch.'/Bloom Dev.app';
    $plugins = $scratch.'/Plugins';
    mkdir($bin, 0755, true);
    mkdir($app, 0755, true);
    mkdir($plugins, 0755, true);

    file_put_contents($bin.'/otool', <<<'SH'
#!/bin/sh
if [ "$1" = "-l" ]; then
  printf '%s\n' 'Load command 0' '      cmd LC_SEGMENT_64'
  exit 0
fi
exit 1
SH);
    file_put_contents($bin.'/dwarfdump', <<<'SH'
#!/bin/sh
echo 'error: missing LC_UUID' >&2
exit 1
SH);
    chmod($bin.'/otool', 0755);
    chmod($bin.'/dwarfdump', 0755);

    $process = new Process(
        ['bash', $root.'/bin/molly-bloom-plugin-register'],
        $root,
        [
            'PATH' => $bin.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
            'BLOOM_APP' => $app,
            'BLOOM_PLUGINS_DIR' => $plugins,
            'HOME' => $scratch,
            'LANG' => 'C',
        ],
    );
    $process->setTimeout(30);
    $process->run();

    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('MollySurfaces has no LC_UUID load command')
            ->and($process->getErrorOutput())->not->toContain('Neither otool nor dwarfdump')
            ->and(is_dir($plugins.'/sifrious.molly'))->toBeFalse();
    } finally {
        (new Process(['rm', '-rf', $scratch]))->run();
    }
});

it('does not link MollySurfaces with -no_uuid', function () {
    $script = file_get_contents(dirname(__DIR__, 2).'/bloom-plugin/Surfaces/build-bundle.sh');

    expect($script)->not->toContain('-Xlinker -no_uuid');
});

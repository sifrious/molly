<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Acceptance\BloomHostInspector;

it('reads a running bloom process and the enabled plugin', function () {
    $plugins = sys_get_temp_dir().'/molly-bloom-plugins-'.bin2hex(random_bytes(4));
    mkdir($plugins.'/sifrious.molly', 0700, true);
    file_put_contents($plugins.'/sifrious.molly/plugin.json', json_encode([
        'id' => 'sifrious.molly',
        'apiVersion' => 1,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($plugins.'/enabled.json', json_encode(['sifrious.molly'], JSON_THROW_ON_ERROR));
    $listing = implode("\n", [
        '11 /usr/bin/open -a /Applications/Bloom.app',
        '4242 /Applications/Bloom Dev.app/Contents/MacOS/Bloom Dev',
        '999 /usr/bin/php /tmp/bloom-plugin/build.php',
    ]);

    $host = (new BloomHostInspector($listing, $plugins))->inspect();
    File::deleteDirectory($plugins);

    expect($host->running)->toBeTrue()
        ->and($host->pid)->toBe(4242)
        ->and($host->command)->toBe('/Applications/Bloom Dev.app/Contents/MacOS/Bloom Dev')
        ->and($host->pluginId)->toBe('sifrious.molly')
        ->and($host->apiVersion)->toBe(1)
        ->and($host->enabled)->toBeTrue()
        ->and($host->processSource)->toBe('supplied-process-listing')
        ->and($host->pluginSource)->toBe($plugins.'/sifrious.molly/plugin.json');
});

it('reads the live process list when the caller does not supply one', function () {
    $plugins = sys_get_temp_dir().'/molly-bloom-plugins-'.bin2hex(random_bytes(4));
    mkdir($plugins, 0700, true);

    $host = (new BloomHostInspector(null, $plugins))->inspect();
    File::deleteDirectory($plugins);

    expect($host->processSource)->toBe('ps -ax -o pid=,command=')
        ->and($host->pluginSource)->toBe($plugins.'/sifrious.molly/plugin.json');
});

it('leaves the host unobserved when the process list has no compiled bloom', function () {
    $plugins = sys_get_temp_dir().'/molly-bloom-plugins-'.bin2hex(random_bytes(4));
    mkdir($plugins, 0700, true);
    file_put_contents($plugins.'/host-telemetry.json', json_encode([
        'plugin_discovered' => true,
        'outcome' => 'PASS',
    ], JSON_THROW_ON_ERROR));

    $host = (new BloomHostInspector("11 /usr/bin/open -a Bloom\n", $plugins))->inspect();
    File::deleteDirectory($plugins);

    expect($host->running)->toBeFalse()
        ->and($host->pid)->toBeNull()
        ->and($host->pluginId)->toBeNull()
        ->and($host->enabled)->toBeFalse();
});

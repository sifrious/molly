<?php

use Sifrious\Molly\Knowledge\GraphManifest;

it('returns an empty manifest when the file is missing', function () {
    $root = sys_get_temp_dir().'/molly-manifest-missing-'.uniqid('', true);
    mkdir($root.'/.molly/graphs', 0700, true);

    $manifest = GraphManifest::load($root);

    expect($manifest->schemaVersion)->toBe(GraphManifest::SCHEMA_VERSION)
        ->and($manifest->units)->toBe([])
        ->and($manifest->lockHash)->toBeNull()
        ->and($manifest->path)->toBe(GraphManifest::pathFor($root));
});

it('loads and normalizes a valid manifest', function () {
    $root = sys_get_temp_dir().'/molly-manifest-ok-'.uniqid('', true);
    $dir = $root.'/.molly/graphs';
    mkdir($dir, 0700, true);
    $payload = [
        'schema_version' => 1,
        'project_path' => $root,
        'lock_path' => $root.'/composer.lock',
        'lock_hash' => 'abc',
        'laravel_exact' => '12.0.0',
        'laravel_major' => 12,
        'packages' => ['laravel/framework' => '12.0.0'],
        'units' => [['id' => 'laravel', 'status' => 'ready']],
        'updated_at' => '2026-09-22T00:00:00+00:00',
    ];
    file_put_contents($dir.'/manifest.json', json_encode($payload, JSON_THROW_ON_ERROR));

    $manifest = GraphManifest::load($root);

    expect($manifest->schemaVersion)->toBe(1)
        ->and($manifest->lockHash)->toBe('abc')
        ->and($manifest->laravelExact)->toBe('12.0.0')
        ->and($manifest->laravelMajor)->toBe(12)
        ->and($manifest->units)->toHaveCount(1)
        ->and($manifest->toArray()['units'][0]['id'])->toBe('laravel');
});

it('classifies malformed JSON', function () {
    $root = sys_get_temp_dir().'/molly-manifest-bad-'.uniqid('', true);
    $dir = $root.'/.molly/graphs';
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/manifest.json', '{not-json');

    expect(fn () => GraphManifest::load($root))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_MANIFEST_INVALID:');
});

it('classifies incompatible schema versions', function () {
    $root = sys_get_temp_dir().'/molly-manifest-ver-'.uniqid('', true);
    $dir = $root.'/.molly/graphs';
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/manifest.json', json_encode(['schema_version' => 99, 'units' => []], JSON_THROW_ON_ERROR));

    expect(fn () => GraphManifest::load($root))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_MANIFEST_INCOMPATIBLE:');
});

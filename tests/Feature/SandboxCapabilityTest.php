<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Execution\Sandbox;
use Sifrious\Molly\Execution\SandboxCapability;

it('records sandbox capability on the local execution target snapshot', function () {
    $snapshot = app(SandboxCapability::class)->snapshot();

    expect($snapshot->kind->value)->toBe('local')
        ->and($snapshot->targetId)->toBe('local')
        ->and($snapshot->provider)->toBe('local')
        ->and($snapshot->toArray()['schema'])->toBe('molly.execution_target_snapshot.v1');
});

it('includes sandbox availability in doctor checks', function () {
    $checks = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'name');

    expect($checks['Sandbox']['code'])->toBeIn(['sandbox_available', 'sandbox_unavailable', 'sandbox_unsafe_override']);
});

it('refuses the safe workflow when this host cannot isolate writer and verifier processes', function () {
    config(['molly.sandbox.allow_unsafe' => false]);
    $capability = app(SandboxCapability::class);

    if ($capability->available()) {
        expect(fn () => $capability->refuseSafeWorkflow())->not->toThrow(RuntimeException::class);

        return;
    }

    expect(fn () => $capability->refuseSafeWorkflow())
        ->toThrow(RuntimeException::class, 'SANDBOX_UNAVAILABLE');
});

it('grants the verifier read access to the project that really owns the workspace vendor directory', function () {
    $root = sys_get_temp_dir().'/molly-sandbox-policy-'.Str::uuid();
    File::ensureDirectoryExists($root.'/project/vendor/bin');
    File::ensureDirectoryExists($root.'/project/tests');
    File::ensureDirectoryExists($root.'/workspace');
    File::put($root.'/project/vendor/autoload.php', '<?php');
    File::put($root.'/project/vendor/bin/pest', '<?php');
    symlink($root.'/project/vendor', $root.'/workspace/vendor');

    try {
        $method = new ReflectionMethod(Sandbox::class, 'readPaths');
        $paths = $method->invoke(app(Sandbox::class), $root.'/workspace', [PHP_BINARY, $root.'/workspace/vendor/bin/pest']);

        expect($paths)->toContain(realpath($root.'/workspace'))
            ->and($paths)->toContain(realpath($root.'/project/vendor'))
            ->and($paths)->toContain(realpath($root.'/project'))
            ->and($paths)->not->toContain(realpath($root));
    } finally {
        File::deleteDirectory($root);
    }
});

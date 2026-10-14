<?php

use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Acceptance\PackageIdentityReader;
use Sifrious\Molly\Acceptance\PackageRoot;

it('proves the installed commit from a clean checkout and withholds it when git is dirty', function () {
    $root = PackageRoot::path();
    $identity = (new PackageIdentityReader)->read();
    $head = trim(Process::path($root)->run(['git', 'rev-parse', 'HEAD'])->output());
    $porcelain = trim(Process::path($root)->run(['git', 'status', '--porcelain'])->output());
    $lock = base_path('composer.lock');

    expect($identity->installPath)->toBe(realpath($root))
        ->and($identity->source)->toBeIn(['injected', 'git', 'unverified'])
        ->and($identity->lockSha256)->toBe(is_file($lock) ? hash_file('sha256', $lock) : null);
    if ($identity->source === 'injected') {
        expect($identity->commit)->toMatch('/\A[0-9a-f]{40}\z/');
    } elseif ($porcelain === '') {
        expect($identity->commit)->toBe($head)
            ->and($identity->source)->toBe('git');
    } else {
        expect($identity->source)->toBe('unverified')
            ->and($identity->commit)->toBeNull();
    }
});

it('hashes the consumer composer.lock when the app has one', function () {
    $lock = base_path('composer.lock');
    $existed = is_file($lock);
    $previous = $existed ? (string) file_get_contents($lock) : null;
    file_put_contents($lock, "{\"packages\":[]}\n");

    try {
        $identity = (new PackageIdentityReader)->read();

        expect($identity->lockSha256)->toBe(hash('sha256', "{\"packages\":[]}\n"));
    } finally {
        if ($existed) {
            file_put_contents($lock, (string) $previous);
        } elseif (is_file($lock)) {
            unlink($lock);
        }
    }
});

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
        ->and($identity->source)->toBeIn(['injected', 'git', 'unverified']);
    if (is_file($lock)) {
        expect($identity->lockSha256)->toBe(hash_file('sha256', $lock));
    }
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

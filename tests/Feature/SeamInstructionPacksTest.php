<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\MollyServiceProvider;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\SeamError;

beforeEach(function () {
    $this->seamOriginalBase = base_path();
    $this->seamWorkspace = sys_get_temp_dir().'/molly-seams-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->seamWorkspace);
    File::put($this->seamWorkspace.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']]]));
});

afterEach(function () {
    app()->setBasePath($this->seamOriginalBase);
    File::deleteDirectory($this->seamWorkspace);
});

function publishSeamPacks(string $workspace): void
{
    app()->setBasePath($workspace);
    app()->register(MollyServiceProvider::class, true);
    expect(Artisan::call('vendor:publish', ['--tag' => ['molly-seams'], '--no-interaction' => true]))->toBe(0);
}

it('loads both complete bundled packs and preserves all 155 source identities', function () {
    $packs = app(InstructionPacks::class);
    $catalogue = $packs->catalogue()['seams'];
    expect($catalogue)->toHaveCount(155)
        ->and(array_unique(array_column($catalogue, 'source_guide_id')))->toHaveCount(155)
        ->and(array_unique(array_column($catalogue, 'parent')))->toHaveCount(155);
    foreach (['controllers', 'commands'] as $seam) {
        $pack = $packs->inspect($this->seamWorkspace, 'laravel-framework/'.$seam);
        expect($pack['source'])->toBe('package')->and($pack['local_changes'])->toBe([])
            ->and($pack['files'])->toHaveCount(7)->and($pack['digest'])->toHaveLength(64);
    }
});

it('publishes complete packs and ordinary republishing preserves direct edits', function () {
    publishSeamPacks($this->seamWorkspace);
    $packs = app(InstructionPacks::class);
    $before = $packs->inspect($this->seamWorkspace, 'laravel-framework/controllers');
    $path = $before['source_path'].'/instructions.md';
    File::append($path, "\nCheck the application's tenant boundary.\n");
    Artisan::call('vendor:publish', ['--tag' => ['molly-seams'], '--no-interaction' => true]);
    $after = $packs->inspect($this->seamWorkspace, 'laravel-framework/controllers');
    expect($after['source'])->toBe('application')->and($after['local_changes'])->toBe(['instructions.md'])
        ->and($after['digest'])->not->toBe($before['digest'])
        ->and(File::get($path))->toContain('tenant boundary');
    $comparison = $packs->compare($this->seamWorkspace, 'laravel-framework/controllers');
    expect($comparison['files']['instructions.md']['original'])->toBe($before['files']['instructions.md']['contents'])
        ->and($comparison['files']['instructions.md']['local_changed'])->toBeTrue()
        ->and($comparison['conflicts'])->toBe([]);
});

it('rejects an incomplete override instead of borrowing package files', function () {
    File::ensureDirectoryExists($this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers');
    expect(fn () => app(InstructionPacks::class)->inspect($this->seamWorkspace, 'laravel-framework/controllers'))
        ->toThrow(SeamError::class, 'INSTRUCTION_OVERRIDE_INCOMPLETE');
});

it('rejects linked overrides and traversal', function () {
    File::ensureDirectoryExists($this->seamWorkspace.'/resources/molly/seams/laravel-framework');
    symlink(app(InstructionPacks::class)->bundledPath().'/laravel-framework/controllers', $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers');
    expect(fn () => app(InstructionPacks::class)->inspect($this->seamWorkspace, 'laravel-framework/controllers'))
        ->toThrow(SeamError::class, 'PATH_OUTSIDE_WORKSPACE');
    expect(fn () => app(InstructionPacks::class)->inspect($this->seamWorkspace, '../controllers'))
        ->toThrow(SeamError::class, 'INPUT_INVALID');
});

it('rejects unsupported package versions', function () {
    File::put($this->seamWorkspace.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]));
    expect(fn () => app(InstructionPacks::class)->inspect($this->seamWorkspace, 'laravel-framework/controllers'))
        ->toThrow(SeamError::class, 'VERSION_UNSUPPORTED');
});

it('rejects executable plan fields and external schema references', function (string $file, Closure $change) {
    publishSeamPacks($this->seamWorkspace);
    $path = $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers/'.$file;
    $document = json_decode(File::get($path), true);
    File::put($path, json_encode($change($document)));
    expect(fn () => app(InstructionPacks::class)->inspect($this->seamWorkspace, 'laravel-framework/controllers'))
        ->toThrow(SeamError::class, 'INSTRUCTION_PACK_INVALID');
})->with([
    'shell field' => ['plan.json', fn (array $plan): array => [...$plan, 'shell' => 'echo passed']],
    'forward dependency' => ['plan.json', function (array $plan): array {
        $plan['steps'][0]['after'] = ['post'];

        return $plan;
    }],
    'remote schema' => ['input.schema.json', fn (array $schema): array => [...$schema, '$ref' => 'https://example.com/schema.json']],
]);

it('reports a three-way conflict without overwriting local files', function () {
    publishSeamPacks($this->seamWorkspace);
    $packs = app(InstructionPacks::class);
    $root = $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers';
    $baseline = json_decode(File::get($root.'/publication.json'), true);
    $baseline['files']['instructions.md'] = "An earlier package instruction.\n";
    $baseline['pack_version'] = '0.9.0';
    File::put($root.'/publication.json', json_encode($baseline));
    File::put($root.'/instructions.md', "A local instruction.\n");
    $comparison = $packs->compare($this->seamWorkspace, 'laravel-framework/controllers');
    expect($comparison['error'])->toBe('INSTRUCTION_UPGRADE_CONFLICT')
        ->and($comparison['conflicts'])->toContain('instructions.md')
        ->and(File::get($root.'/instructions.md'))->toBe("A local instruction.\n");
});

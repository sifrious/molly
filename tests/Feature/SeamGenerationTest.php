<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\PreviewSeamTests;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\SeamError;

require_once __DIR__.'/../Support/SeamFixtures.php';

beforeEach(function () {
    $this->seamWorkspace = sys_get_temp_dir().'/molly-seam-generation-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->seamWorkspace);
    File::put($this->seamWorkspace.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']]]));
});

afterEach(fn () => File::deleteDirectory($this->seamWorkspace));

it('generates stable HTTP tests from explicit behavior without running application code', function () {
    $preview = app(PreviewSeamTests::class);
    $contract = seamHttpContract();
    $first = $preview->handle($this->seamWorkspace, 'laravel-framework/controllers', $contract);
    $second = $preview->handle($this->seamWorkspace, 'laravel-framework/controllers', array_reverse($contract, true));
    expect($first)->toBe($second)->and($first['contents'])->toContain("\$this->json('GET', '/ready'", 'assertStatus(200)', 'assertExactJson(')
        ->and($first['baseline_contents'])->toContain('assertStatus(404)')
        ->and(file_exists($this->seamWorkspace.'/'.$contract['test_path']))->toBeFalse();
});

it('uses the same preview action for console contracts', function () {
    $contract = seamHttpContract();
    $contract['cases'] = [['id' => 'ready', 'description' => 'reports readiness', 'command' => 'readiness:check', 'arguments' => [],
        'expected' => ['output' => 'Ready', 'exit' => 0], 'before' => ['output' => 'Not ready', 'exit' => 1]]];
    $result = app(PreviewSeamTests::class)->handle($this->seamWorkspace, 'laravel-framework/commands', $contract);
    expect($result['contents'])->toContain("\$this->artisan('readiness:check'", "expectsOutput('Ready')->assertExitCode(0)")
        ->and($result['baseline_contents'])->toContain("expectsOutput('Not ready')->assertExitCode(1)");
});

it('refuses to invent missing expected outcomes', function () {
    $contract = seamHttpContract();
    unset($contract['cases'][0]['expected']);
    expect(fn () => app(PreviewSeamTests::class)->handle($this->seamWorkspace, 'laravel-framework/controllers', $contract))
        ->toThrow(SeamError::class, 'CONTRACT_INCOMPLETE');
});

it('distinguishes a planned target from an unresolved existing target', function () {
    $contract = seamHttpContract();
    $contract['target_state'] = 'existing';
    expect(fn () => app(PreviewSeamTests::class)->handle($this->seamWorkspace, 'laravel-framework/controllers', $contract))
        ->toThrow(SeamError::class, 'TARGET_UNRESOLVED');
});

it('reports a collision with a user test without modifying it', function () {
    $contract = seamHttpContract();
    File::ensureDirectoryExists($this->seamWorkspace.'/tests/Feature');
    $path = $this->seamWorkspace.'/'.$contract['test_path'];
    File::put($path, '<?php // My existing test');
    $result = app(PreviewSeamTests::class)->handle($this->seamWorkspace, 'laravel-framework/controllers', $contract);
    expect($result['change'])->toBe('conflict')->and(File::get($path))->toBe('<?php // My existing test');
});

it('refuses a template that replaces generated assertions with executable PHP', function () {
    $root = $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers';
    File::copyDirectory(app(InstructionPacks::class)->bundledPath().'/laravel-framework/controllers', $root);
    File::put($root.'/templates/feature.pest.stub', "<?php\nexpect(true)->toBeTrue();\n{{cases}}\n");
    expect(fn () => app(PreviewSeamTests::class)->handle($this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract()))
        ->toThrow(SeamError::class, 'INSTRUCTION_PACK_INVALID');
});

it('does not let an edited schema remove expected outcomes', function () {
    $root = $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers';
    File::copyDirectory(app(InstructionPacks::class)->bundledPath().'/laravel-framework/controllers', $root);
    File::put($root.'/input.schema.json', json_encode(['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object']));
    $contract = seamHttpContract();
    unset($contract['cases'][0]['expected']);
    expect(fn () => app(PreviewSeamTests::class)->handle($this->seamWorkspace, 'laravel-framework/controllers', $contract))
        ->toThrow(SeamError::class, 'CONTRACT_INCOMPLETE');
});

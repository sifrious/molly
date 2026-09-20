<?php

use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Verification\PestTestAuthoring;

it('teaches ChangeWriter test-authoring mode for writable Pest paths', function (): void {
    $instructions = (new ChangeWriter)->instructions();

    expect($instructions)->toContain('protected_test.writable is true')
        ->and($instructions)->toContain('it() or test()')
        ->and($instructions)->toContain('App\\Livewire\\')
        ->and($instructions)->toContain('/login')
        ->and($instructions)->toContain('/logout')
        ->and($instructions)->toContain('Schema::create')
        ->and($instructions)->toContain('Never register Livewire components');
});

it('accepts the good app-scoped Pest fixture covering login and logout', function (): void {
    $content = file_get_contents(dirname(__DIR__).'/Fixtures/test-authoring/good-home-counter.pest.php');

    expect(app(PestTestAuthoring::class)->issues($content))->toBe([])
        ->and($content)->toContain('App\\Livewire\\HomeCounter')
        ->and($content)->toContain('/login')
        ->and($content)->toContain('/logout')
        ->and($content)->toContain('assertForbidden')
        ->and($content)->toContain('assertGuest');

    app(PestTestAuthoring::class)->assertAcceptable($content);
});

it('rejects the inlined product fixture that embeds schema routes and components', function (): void {
    $content = file_get_contents(dirname(__DIR__).'/Fixtures/test-authoring/bad-inlined-home-counter.php');

    $issues = app(PestTestAuthoring::class)->issues($content);

    expect($issues)->not->toBeEmpty()
        ->and(implode(' ', $issues))->toContain('Schema')
        ->and(implode(' ', $issues))->toContain('Livewire')
        ->and(implode(' ', $issues))->toContain('routes');

    expect(fn () => app(PestTestAuthoring::class)->assertAcceptable($content))
        ->toThrow(RuntimeException::class, 'TEST_AUTHORING_INVALID');
});

it('rejects PHPUnit-only authored tests that Pest cannot discover', function (): void {
    $content = <<<'PHP'
<?php

class HomeCounterTest extends Tests\TestCase
{
    /** @test */
    public function guests_can_see_home(): void
    {
        $this->get('/')->assertOk();
    }
}
PHP;

    expect(app(PestTestAuthoring::class)->issues($content))
        ->toContain('Emit Pest it() or test() Feature cases Pest can discover; do not rely on PHPUnit @test methods alone.');
});

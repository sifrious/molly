<?php

use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Verification\PestTestAuthoring;

it('teaches ChangeWriter test-authoring mode for writable Pest paths', function (): void {
    $instructions = (new ChangeWriter)->instructions();

    expect($instructions)->toContain('protected_test.writable is true')
        ->and($instructions)->toContain('it() or test()')
        ->and($instructions)->toContain('livewire.class_namespace')
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

it('rejects an authored Pest file that omits the opening PHP tag', function (): void {
    $content = "it('returns ready', function () {\n    \$this->getJson('/ready')->assertOk();\n});\n";

    expect(app(PestTestAuthoring::class)->issues($content))
        ->toContain('Start the file with <?php; Pest found no tests in a file without the opening tag.')
        ->and(app(PestTestAuthoring::class)->issues("<?php\n\n".$content))->toBe([])
        ->and(app(PestTestAuthoring::class)->issues("\xEF\xBB\xBF<?php\n".$content))->toBe([]);
});

it('tells the story and change writers to use the installed Livewire layout and to list views', function (): void {
    $change = (new ChangeWriter)->instructions();
    $acceptance = (new AcceptanceWriter)->instructions();

    expect($change)->toContain('livewire.class_namespace', 'livewire.class_directory', 'livewire.view_directory', 'Never use the namespace or directory of another Livewire version.')
        ->and($change)->not->toContain('App\\Livewire')
        ->and($acceptance)->toContain('livewire.class_directory', 'livewire.view_directory', 'Include the Blade view for every page, form, and component a criterion shows');
});

it('asks the story writer for the negative cases and one exact outcome for each protected behavior', function (): void {
    $instructions = (new AcceptanceWriter)->instructions();

    expect($instructions)->toContain(
        'one for invalid credentials, which are rejected with an error and leave the visitor signed out',
        'calls that action or its endpoint directly, without using the page, is refused',
        'Name one exact outcome: HTTP 403 for an action, or a redirect to the sign-in page',
        'Never offer two outcomes joined by or.',
        'the signed-out user is refused the protected action again',
        'what a fresh page load shows afterwards',
        'whether another user sees the change',
    );
});

it('asks the test writer for negative cases and specific assertions', function (): void {
    $instructions = (new ChangeWriter)->instructions();

    expect($instructions)->toContain(
        'invalid credentials (assertGuest and assertSessionHasErrors)',
        'refuses them again, the same way it refuses any guest',
        'call the protected action or endpoint itself as a guest',
        'Never accept either outcome.',
        'assert it again after a fresh request',
        'assert that another user does not see the change',
        'Never pass a single character or a bare number to assertSee() or assertDontSee()',
    );
});

it('rejects an authored test whose assertSee checks one character, as the RC9 test did', function (): void {
    $rc9 = file_get_contents(dirname(__DIR__).'/Fixtures/test-authoring/rc9-hello-counter/HelloCounterTest.rc9.php');
    $specific = "<?php\n\nit('shows the total', function () {\n    \$this->get('/')->assertSee('Total: 12')->assertDontSee('Sign in');\n});\n";

    expect(app(PestTestAuthoring::class)->issues($rc9))->toBe(["Assert specific rendered text, not a single character: assertSee('0'), assertSee('1') pass or fail on almost any page, so they prove nothing."])
        ->and(app(PestTestAuthoring::class)->issues($specific))->toBe([])
        ->and(fn () => app(PestTestAuthoring::class)->assertAcceptable(str_replace("'Total: 12'", '7', $specific)))
        ->toThrow(RuntimeException::class, 'TEST_AUTHORING_INVALID: Assert specific rendered text, not a single character: assertSee(7) passes or fails on almost any page, so it proves nothing.');
});

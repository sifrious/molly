<?php

use Sifrious\Molly\Actions\CheckModelReadiness;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\ReadinessCheck;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Verification\PestAssertionHints;

/*
 * The acceptance journey includes a held-out story written to expose prompts tuned to the
 * demo. Every text Molly writes for a model must hold for any Laravel application, so none
 * may name the demo's component, copy, or action.
 */

/** @return array<string, string> Every Molly-authored text a model reads, by source. */
function mollyAuthoredPrompts(): array
{
    $prompts = [
        'AcceptanceWriter' => (new AcceptanceWriter)->instructions(),
        'ChangeWriter' => (new ChangeWriter)->instructions(),
        'TarpitReviewer' => (new TarpitReviewer)->instructions(),
        'ReadinessCheck' => (new ReadinessCheck)->instructions(),
        'readiness task' => CheckModelReadiness::TASK."\n".CheckModelReadiness::TEST,
        'assertion hint' => app(PestAssertionHints::class)->handle("Expected response status code [403] but received 200.\nFailed asserting that 200 is identical to 403.")[0]['hint'],
    ];
    foreach ((new ReflectionClassConstant(RecordRedBaseline::class, 'TEST_CAUSES'))->getValue() as $cause => [$explanation, $guidance]) {
        $prompts['authored test cause '.$cause] = $explanation."\n".$guidance;
    }

    return $prompts;
}

it('names no demo noun in any prompt Molly writes for a model', function () {
    foreach (mollyAuthoredPrompts() as $source => $prompt) {
        foreach (['counter', 'hello stranger', 'hello world', 'increment'] as $noun) {
            expect(stripos($prompt, $noun))->toBeFalse($source.' names the demo noun "'.$noun.'".');
        }
        expect($prompt)->not->toContain('—', $source.' contains an em dash.');
    }
});

it('keeps the general authorization and authentication guidance the demo wording carried', function () {
    $instructions = (new ChangeWriter)->instructions();

    expect($instructions)
        // Action-level 403 for an unauthorized component action.
        ->toContain('deny the unauthorized or unauthenticated caller inside that action with abort(403)')
        ->toContain('silent no-op')
        ->toContain('itself as a guest')
        // Sign-in and sign-out when the task names them.
        ->toContain('/login')
        ->toContain('/logout')
        // The test exercises the application and never defines it.
        ->toContain('it must not define the product under test')
        ->toContain('Never register Livewire components, define application routes, or call Schema::create');
});

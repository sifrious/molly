<?php

use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Verification\PestAssertionHints;

it('hints Livewire assertForbidden mismatches toward action-level 403', function (): void {
    $output = json_encode([
        'tool' => 'pest',
        'result' => 'failed',
        'failures' => [[
            'test' => 'P\\Tests\\Feature\\HomeCounterTest::__pest_evaluable_it_does_not_give_guests_an_operational_counter',
            'message' => "Expected response status code [403] but received 200.\nFailed asserting that 200 is identical to 403.",
        ]],
    ], JSON_THROW_ON_ERROR);

    $hints = app(PestAssertionHints::class)->handle($output);

    expect($hints)->toHaveCount(1)
        ->and($hints[0]['pattern'])->toBe('livewire_assert_forbidden')
        ->and($hints[0]['hint'])->toContain('abort(403)')
        ->and($hints[0]['hint'])->toContain('silent no-op')
        ->and($hints[0]['hint'])->toContain('whole page GET');
});

it('stays quiet when Pest output has no forbidden mismatch', function (): void {
    expect(app(PestAssertionHints::class)->handle('Failed asserting that true is false.'))->toBe([]);
});

it('teaches ChangeWriter that assertForbidden needs action-level 403', function (): void {
    $instructions = (new ChangeWriter)->instructions();

    expect($instructions)->toContain('assertForbidden()')
        ->and($instructions)->toContain('abort(403)')
        ->and($instructions)->toContain('silent no-op')
        ->and($instructions)->toContain('assertion_hints');
});

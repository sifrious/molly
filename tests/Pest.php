<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Classification\ChoiceClassification;
use Sifrious\Molly\Classification\ChoiceClassifier;
use Sifrious\Molly\Tests\Support\FakeChoiceClassifier;
use Sifrious\Molly\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

function writeProtectedTest(string $workspace, string $path = 'tests/GreetingTest.php', string $contents = '<?php it("exists", fn () => expect(true)->toBeTrue());'): string
{
    File::ensureDirectoryExists($workspace.'/'.dirname($path));
    File::put($workspace.'/'.$path, $contents);

    return hash('sha256', $contents);
}

/**
 * Open the Jev gate for one test and bind a deterministic classifier in place of Laravel AI.
 * Pass available: false to prove the enabled-but-unavailable state.
 */
function fakeJev(Closure|ChoiceClassification|Throwable|null $answer = null, bool $available = true): FakeChoiceClassifier
{
    config(['molly.jev.enabled' => true, 'ai.providers.typesafe.key' => 'test-key']);
    $fake = new FakeChoiceClassifier($answer, $available);
    app()->instance(ChoiceClassifier::class, $fake);

    return $fake;
}

/** @param list<string> $options */
function jevChoice(string $choice, array $options = ['continue', 'retry', 'stop', 'needs_review'], float $confidence = 0.9, ?string $model = 'jev-latest'): ChoiceClassification
{
    return new ChoiceClassification($choice, array_replace(array_fill_keys($options, 0.0), [$choice => 1.0]), $confidence, $model);
}

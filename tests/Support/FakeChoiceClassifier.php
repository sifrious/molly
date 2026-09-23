<?php

namespace Sifrious\Molly\Tests\Support;

use Closure;
use Sifrious\Molly\Classification\ChoiceClassification;
use Sifrious\Molly\Classification\ChoiceClassifier;
use Throwable;

/**
 * Deterministic stand-in for the Laravel AI choice classifier. It records
 * every bounded request so tests can prove what Molly sent, and answers with
 * a fixed classification, a closure, or a throwable.
 */
final class FakeChoiceClassifier implements ChoiceClassifier
{
    /** @var list<array{state: array<string, mixed>, question: string, instructions: string, criteria: array<string, string>, model: string, timeout: int}> */
    public array $requests = [];

    public function __construct(
        private Closure|ChoiceClassification|Throwable|null $answer = null,
        private bool $available = true,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function choose(array $state, string $question, string $instructions, array $criteria, string $model, int $timeout): ?ChoiceClassification
    {
        $this->requests[] = compact('state', 'question', 'instructions', 'criteria', 'model', 'timeout');
        $answer = $this->answer instanceof Closure ? ($this->answer)($state, $question, $criteria) : $this->answer;
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /** @return array<string, mixed>|null */
    public function state(int $index = 0): ?array
    {
        return $this->requests[$index]['state'] ?? null;
    }
}

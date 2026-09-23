<?php

namespace Sifrious\Molly\Classification;

use Throwable;

/**
 * The one seam through which Molly asks a bounded single-choice question.
 * Production binds the Laravel AI implementation; tests bind deterministic fakes.
 */
interface ChoiceClassifier
{
    /**
     * Whether the exact usable classification surface is installed.
     */
    public function available(): bool;

    /**
     * Ask one single-choice question about bounded state.
     * Returns null when the provider answered without a usable choice.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $criteria
     *
     * @throws Throwable when the provider request fails
     */
    public function choose(array $state, string $question, string $instructions, array $criteria, string $model, int $timeout): ?ChoiceClassification;
}

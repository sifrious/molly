<?php

namespace Sifrious\Molly\Classification;

/**
 * A provider's answer to one single-choice question, before Molly validates it.
 */
final readonly class ChoiceClassification
{
    /**
     * @param  array<string, mixed>  $probabilities
     */
    public function __construct(
        public string $choice,
        public array $probabilities,
        public ?float $confidence,
        public ?string $model = null,
    ) {}
}

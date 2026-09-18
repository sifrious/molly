<?php

namespace Sifrious\Molly\Classification;

interface ClassificationAdapter
{
    public function name(): string;

    public function available(): bool;

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function classify(array $evidence): ClassificationDecision;
}

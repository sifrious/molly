<?php

namespace Sifrious\Molly\Seams;

use Sifrious\Molly\Verification\VerificationState;

final readonly class StepResult
{
    public function __construct(public VerificationState $state, public array $expected, public array $observed, public ?SeamError $error = null) {}

    public function toArray(): array
    {
        return ['state' => $this->state->value, 'expected' => $this->expected, 'observed' => $this->observed, 'error' => $this->error?->toArray()];
    }
}

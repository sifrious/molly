<?php

namespace Sifrious\Molly\ModelFit;

final readonly class AdmissionDecision
{
    /** @param list<string> $rejectionReasons */
    public function __construct(
        public bool $approved,
        public array $rejectionReasons,
    ) {}
}

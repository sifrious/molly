<?php

namespace Sifrious\Molly\Classification;

final readonly class ClassificationDecision
{
    /**
     * @param  list<string>  $evidenceRefs
     */
    public function __construct(
        public string $adapter,
        public string $action,
        public ?float $confidence,
        public array $evidenceRefs,
        public string $deterministicFollowUp,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter,
            'action' => $this->action,
            'confidence' => $this->confidence,
            'evidence_refs' => $this->evidenceRefs,
            'deterministic_follow_up' => $this->deterministicFollowUp,
        ];
    }
}

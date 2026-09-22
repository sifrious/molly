<?php

namespace Sifrious\Molly\Classification;

final readonly class ClassificationDecision
{
    /**
     * @param  list<string>  $evidenceRefs
     * @param  string|array<string, mixed>|null  $result
     */
    public function __construct(
        public string $adapter,
        public string $action,
        public ?float $confidence,
        public array $evidenceRefs,
        public string $deterministicFollowUp,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $laravelAiVersion = null,
        public ?string $question = null,
        public string|array|null $result = null,
        public ?float $probability = null,
        public ?float $threshold = null,
        public ?string $fallbackReason = null,
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
            'provider' => $this->provider,
            'model' => $this->model,
            'laravel_ai_version' => $this->laravelAiVersion,
            'question' => $this->question,
            'result' => $this->result,
            'probability' => $this->probability,
            'threshold' => $this->threshold,
            'fallback_reason' => $this->fallbackReason,
        ];
    }
}

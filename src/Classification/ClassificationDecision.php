<?php

namespace Sifrious\Molly\Classification;

final readonly class ClassificationDecision
{
    public const PROVENANCE_NOT_MEASURED = 'not_measured';

    public const PROVENANCE_DISABLED = 'disabled';

    public const PROVENANCE_UNSUPPORTED = 'unsupported';

    public const PROVENANCE_PROVIDER_FAILURE = 'provider_failure';

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
        public ?string $provenanceStatus = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $status = $this->provenanceStatus;
        if ($status === null) {
            if ($this->fallbackReason === self::PROVENANCE_DISABLED) {
                $status = self::PROVENANCE_DISABLED;
            } elseif ($this->fallbackReason === self::PROVENANCE_UNSUPPORTED) {
                $status = self::PROVENANCE_UNSUPPORTED;
            } elseif ($this->fallbackReason === self::PROVENANCE_PROVIDER_FAILURE) {
                $status = self::PROVENANCE_PROVIDER_FAILURE;
            } elseif ($this->probability === null && $this->confidence === null && $this->provider === null && $this->model === null) {
                $status = self::PROVENANCE_NOT_MEASURED;
            }
        }

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
            // Never synthesize a probability for the decide macro — null means not measured.
            'probability' => $this->probability,
            'threshold' => $this->threshold,
            'fallback_reason' => $this->fallbackReason,
            'provenance_status' => $status,
        ];
    }
}

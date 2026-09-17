<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

final readonly class ProbeResult
{
    /**
     * @param  array<string, mixed>|null  $metrics  probe-specific numbers; null when skipped or errored
     * @param  list<string>  $caveats  verbatim talk caveats
     * @param  list<string>  $notes  static documented divergences from the hand-verify one-liner
     * @param  list<string>  $warnings  runtime observations (missing paths, unreadable files, shallow clone)
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $prints,
        public ProbeStatus $status,
        public ?string $skipReason,
        public ?array $metrics,
        public ?string $headline,
        public string $handVerify,
        public string $pairsWith,
        public array $caveats,
        public array $notes,
        public array $warnings,
        public int $durationMs = 0,
    ) {}

    public function withDurationMs(int $durationMs): self
    {
        return new self(
            $this->key,
            $this->name,
            $this->prints,
            $this->status,
            $this->skipReason,
            $this->metrics,
            $this->headline,
            $this->handVerify,
            $this->pairsWith,
            $this->caveats,
            $this->notes,
            $this->warnings,
            $durationMs,
        );
    }

    /**
     * @return array{key: string, name: string, prints: string, status: string, skip_reason: string|null, metrics: array<string, mixed>|null, headline: string|null, hand_verify: string, pairs_with: string, caveats: list<string>, notes: list<string>, warnings: list<string>, duration_ms: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'prints' => $this->prints,
            'status' => $this->status->value,
            'skip_reason' => $this->skipReason,
            'metrics' => $this->metrics,
            'headline' => $this->headline,
            'hand_verify' => $this->handVerify,
            'pairs_with' => $this->pairsWith,
            'caveats' => $this->caveats,
            'notes' => $this->notes,
            'warnings' => $this->warnings,
            'duration_ms' => $this->durationMs,
        ];
    }
}

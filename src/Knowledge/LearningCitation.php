<?php

namespace Sifrious\Molly\Knowledge;

final readonly class LearningCitation
{
    public function __construct(
        public string $path,
        public ?string $revisionRef = null,
        public ?int $lineStart = null,
        public ?int $lineEnd = null,
    ) {}

    /** @return array{path: string, revisionRef: string|null, lineStart: int|null, lineEnd: int|null} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'revisionRef' => $this->revisionRef,
            'lineStart' => $this->lineStart,
            'lineEnd' => $this->lineEnd,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['path'] ?? ''),
            isset($data['revisionRef']) && is_string($data['revisionRef']) ? $data['revisionRef'] : null,
            isset($data['lineStart']) && is_int($data['lineStart']) ? $data['lineStart'] : null,
            isset($data['lineEnd']) && is_int($data['lineEnd']) ? $data['lineEnd'] : null,
        );
    }
}

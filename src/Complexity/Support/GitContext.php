<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

final readonly class GitContext
{
    public function __construct(
        public bool $available,
        public ?string $branch,
        public ?string $sha,
        public ?bool $dirty,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, null, null, null);
    }

    /**
     * @return array{available: bool, branch: string|null, sha: string|null, dirty: bool|null}
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'branch' => $this->branch,
            'sha' => $this->sha,
            'dirty' => $this->dirty,
        ];
    }
}

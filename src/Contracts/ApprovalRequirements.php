<?php

namespace Sifrious\Molly\Contracts;

final readonly class ApprovalRequirements
{
    public function __construct(
        public bool $beforePullRequest,
        public bool $beforeMerge,
        public bool $beforeRemoteDispatch,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            JsonDocument::boolean($data, 'before_pull_request'),
            JsonDocument::boolean($data, 'before_merge'),
            JsonDocument::boolean($data, 'before_remote_dispatch'),
        );
    }

    public static function defaults(): self
    {
        return new self(true, true, true);
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            'before_pull_request' => $this->beforePullRequest,
            'before_merge' => $this->beforeMerge,
            'before_remote_dispatch' => $this->beforeRemoteDispatch,
        ];
    }
}

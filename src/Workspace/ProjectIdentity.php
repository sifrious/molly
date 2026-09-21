<?php

namespace Sifrious\Molly\Workspace;

use InvalidArgumentException;
use Sifrious\Molly\Contracts\JsonDocument;

/** Stable Molly project identity — never a filesystem path. */
final readonly class ProjectIdentity
{
    public function __construct(public string $id)
    {
        JsonDocument::assertUuid($this->id, 'project_id');
    }

    public static function mint(): self
    {
        return new self((string) \Illuminate\Support\Str::uuid());
    }

    public static function fromString(string $id): self
    {
        return new self($id);
    }
}

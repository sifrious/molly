<?php

namespace Sifrious\Molly\Workspace;

use Sifrious\Molly\Contracts\JsonDocument;

/** Stable Molly workspace identity — survives path moves. */
final readonly class WorkspaceIdentity
{
    public function __construct(public string $id)
    {
        JsonDocument::assertUuid($this->id, 'workspace_id');
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

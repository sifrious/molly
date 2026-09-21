<?php

namespace Sifrious\Molly\Workspace;

use InvalidArgumentException;

/** Immutable starting revision (Git object id). */
final readonly class RevisionIdentity
{
    public string $sha;

    public function __construct(string $sha)
    {
        if (! preg_match('/\A[0-9a-f]{40}\z/i', $sha)) {
            throw new InvalidArgumentException('REVISION_INVALID: base revision must be a 40-character Git SHA.');
        }
        $this->sha = strtolower($sha);
    }

    public static function fromString(string $sha): self
    {
        return new self($sha);
    }
}

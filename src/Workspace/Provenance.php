<?php

namespace Sifrious\Molly\Workspace;

use InvalidArgumentException;
use JsonSerializable;

/** Captured execution provenance — path is metadata, not identity. */
final readonly class Provenance implements JsonSerializable
{
    public const SCHEMA = 'molly.provenance.v1';

    /** @param  array<string, string|int|float|bool|null>  $environment */
    public function __construct(
        public WorkspaceReference $reference,
        public string $startingRevision,
        public string $executionPath,
        public string $capturedAt,
        public array $environment = [],
    ) {
        if (! preg_match('/\A[0-9a-f]{40}\z/i', $this->startingRevision)) {
            throw new InvalidArgumentException('PROVENANCE_INVALID: starting_revision must be a 40-character Git SHA.');
        }
        if ($this->executionPath === '') {
            throw new InvalidArgumentException('PROVENANCE_INVALID: execution_path is required.');
        }
    }

    /** @param  array<string, string|int|float|bool|null>  $environment */
    public static function capture(WorkspaceReference $reference, array $environment = []): self
    {
        $reference->assertAvailableForExecution();

        return new self(
            reference: $reference,
            startingRevision: $reference->head->sha,
            executionPath: $reference->currentPath,
            capturedAt: gmdate(DATE_ATOM),
            environment: $environment,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'workspace' => $this->reference->toArray(),
            'starting_revision' => strtolower($this->startingRevision),
            'execution_path' => $this->executionPath,
            'captured_at' => $this->capturedAt,
            'environment' => $this->environment,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

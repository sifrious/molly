<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;

final readonly class HandoffEnvelope
{
    public const SCHEMA = 'molly.handoff_envelope.v1';

    /**
     * @param  list<string>  $allowedPaths
     * @param  list<string>  $protectedTests
     * @param  list<string>  $priorDiagnostics
     * @param  list<string>  $artifacts
     */
    public function __construct(
        public string $handoffId,
        public string $sourceTaskId,
        public string $sourceRunId,
        public string $senderWorkspaceId,
        public string $recipientWorkspaceId,
        public string $boundedContext,
        public array $allowedPaths,
        public array $protectedTests,
        public array $priorDiagnostics,
        public array $artifacts,
        public string $requestedNextAction,
        public string $provenance,
    ) {
        JsonDocument::assertUuid($this->handoffId, 'handoff_id');
        JsonDocument::assertUuid($this->sourceTaskId, 'source_task_id');
        JsonDocument::assertUuid($this->sourceRunId, 'source_run_id');
        JsonDocument::assertUuid($this->senderWorkspaceId, 'sender_workspace_id');
        JsonDocument::assertUuid($this->recipientWorkspaceId, 'recipient_workspace_id');
        if ($this->senderWorkspaceId === $this->recipientWorkspaceId) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A handoff cannot target the same workspace.');
        }
        if (trim($this->boundedContext) === '' || strlen($this->boundedContext) > 8192) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: bounded_context must be 1 to 8192 bytes.');
        }
        if ($this->allowedPaths === [] || $this->protectedTests === []) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A handoff needs allowed paths and protected tests.');
        }
        if (array_intersect($this->allowedPaths, $this->protectedTests) !== []) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: Protected tests cannot also be writable.');
        }
        if ($this->requestedNextAction === '' || $this->provenance === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: requested_next_action and provenance are required.');
        }
        foreach ($this->allowedPaths as $path) {
            JsonDocument::relativePath($path, 'allowed_paths');
        }
        foreach ($this->protectedTests as $path) {
            JsonDocument::relativePath($path, 'protected_tests');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        JsonDocument::requireSchema($data, self::SCHEMA);

        return new self(
            JsonDocument::uuid($data, 'handoff_id'),
            JsonDocument::uuid($data, 'source_task_id'),
            JsonDocument::uuid($data, 'source_run_id'),
            JsonDocument::uuid($data, 'sender_workspace_id'),
            JsonDocument::uuid($data, 'recipient_workspace_id'),
            JsonDocument::string($data, 'bounded_context'),
            JsonDocument::stringList($data, 'allowed_paths'),
            JsonDocument::stringList($data, 'protected_tests'),
            JsonDocument::stringList($data, 'prior_diagnostics'),
            JsonDocument::stringList($data, 'artifacts'),
            JsonDocument::string($data, 'requested_next_action'),
            JsonDocument::string($data, 'provenance'),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(JsonDocument::decode($json));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'handoff_id' => $this->handoffId,
            'source_task_id' => $this->sourceTaskId,
            'source_run_id' => $this->sourceRunId,
            'sender_workspace_id' => $this->senderWorkspaceId,
            'recipient_workspace_id' => $this->recipientWorkspaceId,
            'bounded_context' => $this->boundedContext,
            'allowed_paths' => $this->allowedPaths,
            'protected_tests' => $this->protectedTests,
            'prior_diagnostics' => $this->priorDiagnostics,
            'artifacts' => $this->artifacts,
            'requested_next_action' => $this->requestedNextAction,
            'provenance' => $this->provenance,
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }
}

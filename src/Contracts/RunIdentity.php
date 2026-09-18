<?php

namespace Sifrious\Molly\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RunIdentity
{
    public const SCHEMA = 'molly.run_identity.v1';

    public function __construct(
        public string $runId,
        public string $attemptId,
        public int $attemptNumber,
        public string $taskId,
        public ?string $parentRunId,
        public ?string $handoffId,
        public ?string $bloomSessionId,
        public string $provider,
        public ?string $model,
        public string $bloomWorkspaceId,
        public string $workspacePath,
        public string $branch,
        public string $baseSha,
        public DateTimeImmutable $startedAt,
        public string $configurationVersion,
    ) {
        JsonDocument::assertUuid($this->runId, 'run_id');
        JsonDocument::assertUuid($this->attemptId, 'attempt_id');
        JsonDocument::assertUuid($this->taskId, 'task_id');
        JsonDocument::assertUuid($this->bloomWorkspaceId, 'bloom_workspace_id');
        if ($this->parentRunId !== null) {
            JsonDocument::assertUuid($this->parentRunId, 'parent_run_id');
        }
        if ($this->handoffId !== null) {
            JsonDocument::assertUuid($this->handoffId, 'handoff_id');
        }
        if ($this->bloomSessionId !== null) {
            JsonDocument::assertUuid($this->bloomSessionId, 'bloom_session_id');
        }
        if ($this->attemptNumber < 1 || $this->attemptNumber > 10) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: attempt_number must be between 1 and 10.');
        }
        if ($this->provider === '' || $this->workspacePath === '' || $this->branch === '' || $this->configurationVersion === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: provider, workspace_path, branch, and configuration_version are required.');
        }
        if (! preg_match('/\A[0-9a-f]{40}\z/', $this->baseSha)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: base_sha must be a 40-character Git SHA.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        JsonDocument::requireSchema($data, self::SCHEMA);

        return new self(
            JsonDocument::uuid($data, 'run_id'),
            JsonDocument::uuid($data, 'attempt_id'),
            JsonDocument::integer($data, 'attempt_number', 1, 10),
            JsonDocument::uuid($data, 'task_id'),
            JsonDocument::optionalUuid($data, 'parent_run_id'),
            JsonDocument::optionalUuid($data, 'handoff_id'),
            JsonDocument::optionalUuid($data, 'bloom_session_id'),
            JsonDocument::string($data, 'provider'),
            JsonDocument::optionalString($data, 'model'),
            JsonDocument::uuid($data, 'bloom_workspace_id'),
            JsonDocument::string($data, 'workspace_path'),
            JsonDocument::string($data, 'branch'),
            JsonDocument::string($data, 'base_sha'),
            JsonDocument::time($data, 'started_at'),
            JsonDocument::string($data, 'configuration_version'),
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
            'run_id' => $this->runId,
            'attempt_id' => $this->attemptId,
            'attempt_number' => $this->attemptNumber,
            'task_id' => $this->taskId,
            'parent_run_id' => $this->parentRunId,
            'handoff_id' => $this->handoffId,
            'bloom_session_id' => $this->bloomSessionId,
            'provider' => $this->provider,
            'model' => $this->model,
            'bloom_workspace_id' => $this->bloomWorkspaceId,
            'workspace_path' => $this->workspacePath,
            'branch' => $this->branch,
            'base_sha' => $this->baseSha,
            'started_at' => JsonDocument::formatTime($this->startedAt),
            'configuration_version' => $this->configurationVersion,
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }
}

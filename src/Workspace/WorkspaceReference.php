<?php

namespace Sifrious\Molly\Workspace;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Molly-owned workspace reference (paths are observed metadata only).
 *
 * Schema intentionally uses molly.* ids so Molly may diverge from Stacks.
 */
final readonly class WorkspaceReference implements JsonSerializable
{
    public const SCHEMA = 'molly.workspace-reference.v1';

    public function __construct(
        public ProjectIdentity $project,
        public WorkspaceIdentity $workspace,
        public string $repositoryId,
        public ?string $repositoryRemoteIdentity,
        public string $checkoutId,
        public string $checkoutKind,
        public string $availability,
        public string $currentPath,
        public ?string $branch,
        public ?RevisionIdentity $head,
        public ?string $bloomWorkspaceId = null,
    ) {
        if ($this->repositoryId === '' || $this->checkoutId === '') {
            throw new InvalidArgumentException('WORKSPACE_REFERENCE_INVALID: repository and checkout IDs are required.');
        }
        if (! in_array($this->availability, ['available', 'unavailable', 'ambiguous'], true)) {
            throw new InvalidArgumentException("WORKSPACE_REFERENCE_INVALID: unsupported availability {$this->availability}");
        }
        if ($this->currentPath === '') {
            throw new InvalidArgumentException('WORKSPACE_REFERENCE_INVALID: current_path evidence is required.');
        }
        if (! in_array($this->checkoutKind, ['worktree', 'clone', 'detached', 'unknown'], true)) {
            throw new InvalidArgumentException("WORKSPACE_REFERENCE_INVALID: unsupported checkout kind {$this->checkoutKind}");
        }
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        if (($payload['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('WORKSPACE_REFERENCE_INVALID: unsupported or missing schema.');
        }

        $head = $payload['head'] ?? null;

        return new self(
            project: ProjectIdentity::fromString((string) $payload['project_id']),
            workspace: WorkspaceIdentity::fromString((string) $payload['workspace_id']),
            repositoryId: (string) ($payload['repository']['id'] ?? ''),
            repositoryRemoteIdentity: isset($payload['repository']['remote_identity']) ? (string) $payload['repository']['remote_identity'] : null,
            checkoutId: (string) ($payload['checkout']['id'] ?? ''),
            checkoutKind: (string) ($payload['checkout']['kind'] ?? 'unknown'),
            availability: (string) ($payload['availability'] ?? ''),
            currentPath: (string) ($payload['current_path'] ?? ''),
            branch: isset($payload['branch']) ? (string) $payload['branch'] : null,
            head: is_string($head) && $head !== '' ? RevisionIdentity::fromString($head) : null,
            bloomWorkspaceId: isset($payload['bloom_workspace_id']) ? (string) $payload['bloom_workspace_id'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'project_id' => $this->project->id,
            'workspace_id' => $this->workspace->id,
            'repository' => [
                'id' => $this->repositoryId,
                'remote_identity' => $this->repositoryRemoteIdentity,
            ],
            'checkout' => [
                'id' => $this->checkoutId,
                'kind' => $this->checkoutKind,
            ],
            'availability' => $this->availability,
            'current_path' => $this->currentPath,
            'branch' => $this->branch,
            'head' => $this->head?->sha,
            'bloom_workspace_id' => $this->bloomWorkspaceId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function assertAvailableForExecution(): void
    {
        if ($this->availability !== 'available') {
            throw new InvalidArgumentException("WORKSPACE_UNAVAILABLE: workspace {$this->workspace->id} is {$this->availability}.");
        }
        if ($this->head === null) {
            throw new InvalidArgumentException("WORKSPACE_REVISION_MISSING: workspace {$this->workspace->id} has no starting revision.");
        }
    }
}

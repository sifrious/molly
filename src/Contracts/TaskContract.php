<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;

final readonly class TaskContract
{
    public const SCHEMA = 'molly.task_contract.v1';

    /**
     * @param  list<string>  $allowedWritePaths
     * @param  list<string>  $protectedPaths
     * @param  list<AcceptanceTest>  $acceptanceTests
     */
    public function __construct(
        public string $taskId,
        public string $request,
        public RepositoryIdentity $repository,
        public string $bloomWorkspaceId,
        public string $workspacePath,
        public string $branch,
        public string $baseSha,
        public array $allowedWritePaths,
        public array $protectedPaths,
        public array $acceptanceTests,
        public int $attemptBudget,
        public VerifierPolicyMap $verifierPolicies,
        public ExecutionTargetRequest $executionTarget,
        public ApprovalRequirements $approval,
    ) {
        JsonDocument::assertUuid($this->taskId, 'task_id');
        JsonDocument::assertUuid($this->bloomWorkspaceId, 'bloom_workspace_id');
        if (trim($this->request) === '' || strlen($this->request) > 8192) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: request must be 1 to 8192 bytes.');
        }
        if ($this->workspacePath === '' || $this->branch === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: workspace_path and branch are required.');
        }
        if (! preg_match('/\A[0-9a-f]{40}\z/', $this->baseSha)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: base_sha must be a 40-character Git SHA.');
        }
        if ($this->attemptBudget < 1 || $this->attemptBudget > 10) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: attempt_budget must be between 1 and 10.');
        }
        if ($this->allowedWritePaths === [] || count($this->allowedWritePaths) > 8) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: allowed_write_paths must contain 1 to 8 paths.');
        }
        if ($this->acceptanceTests === []) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: At least one acceptance test is required.');
        }

        $this->assertPaths($this->allowedWritePaths, 'allowed_write_paths');
        $this->assertPaths($this->protectedPaths, 'protected_paths');
        foreach ($this->acceptanceTests as $test) {
            if (! in_array($test->path, $this->protectedPaths, true)) {
                throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: Each acceptance test path must also be protected.');
            }
            if (in_array($test->path, $this->allowedWritePaths, true)) {
                throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: An acceptance test cannot also be writable.');
            }
        }
        if (array_intersect($this->allowedWritePaths, $this->protectedPaths) !== []) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: Allowed write paths and protected paths cannot overlap.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        JsonDocument::requireSchema($data, self::SCHEMA);

        return new self(
            JsonDocument::uuid($data, 'task_id'),
            JsonDocument::string($data, 'request'),
            RepositoryIdentity::fromArray(JsonDocument::object($data, 'repository')),
            JsonDocument::uuid($data, 'bloom_workspace_id'),
            JsonDocument::string($data, 'workspace_path'),
            JsonDocument::string($data, 'branch'),
            JsonDocument::string($data, 'base_sha'),
            array_map(fn (string $path): string => JsonDocument::relativePath($path, 'allowed_write_paths'), JsonDocument::stringList($data, 'allowed_write_paths')),
            array_map(fn (string $path): string => JsonDocument::relativePath($path, 'protected_paths'), JsonDocument::stringList($data, 'protected_paths')),
            array_map(AcceptanceTest::fromArray(...), JsonDocument::objectList($data, 'acceptance_tests')),
            JsonDocument::integer($data, 'attempt_budget', 1, 10),
            VerifierPolicyMap::fromArray(JsonDocument::object($data, 'verifier_policies')),
            ExecutionTargetRequest::fromArray(JsonDocument::object($data, 'execution_target')),
            ApprovalRequirements::fromArray(JsonDocument::object($data, 'approval')),
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
            'task_id' => $this->taskId,
            'request' => $this->request,
            'repository' => $this->repository->toArray(),
            'bloom_workspace_id' => $this->bloomWorkspaceId,
            'workspace_path' => $this->workspacePath,
            'branch' => $this->branch,
            'base_sha' => $this->baseSha,
            'allowed_write_paths' => $this->allowedWritePaths,
            'protected_paths' => $this->protectedPaths,
            'acceptance_tests' => array_map(fn (AcceptanceTest $test): array => $test->toArray(), $this->acceptanceTests),
            'attempt_budget' => $this->attemptBudget,
            'verifier_policies' => $this->verifierPolicies->toArray(),
            'execution_target' => $this->executionTarget->toArray(),
            'approval' => $this->approval->toArray(),
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }

    /** @param  list<string>  $paths */
    private function assertPaths(array $paths, string $key): void
    {
        if (count(array_unique($paths)) !== count($paths)) {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} cannot contain duplicates.");
        }
        foreach ($paths as $path) {
            JsonDocument::relativePath($path, $key);
        }
    }
}

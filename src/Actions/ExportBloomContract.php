<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Contracts\AcceptanceTest;
use Sifrious\Molly\Contracts\ApprovalRequirements;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Contracts\RepositoryIdentity;
use Sifrious\Molly\Contracts\TaskContract;
use Sifrious\Molly\Contracts\VerifierPolicyMap;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;

class ExportBloomContract
{
    public function __construct(private SecretRedactor $redactor) {}

    public function handle(string $reference, string $bloomWorkspaceId, string $branch, string $baseSha, string $owner = 'local', string $name = 'workspace'): TaskContract
    {
        $task = app(ShowTask::class)->handle($reference);
        if ($task === null) {
            throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        }
        if (! is_string($task->test_digest) || $task->test_digest === '') {
            throw new RuntimeException('PROTECTED_TEST_MISSING: Export a Bloom contract only after the required Pest test is approved.');
        }
        if ($task->allow_test_edits) {
            throw new RuntimeException('TEST_PROTECTED: Export a Bloom contract only for the default protected-test workflow.');
        }

        $contract = new TaskContract(
            $task->id,
            $this->redactor->text($task->prompt, $task->workspace),
            new RepositoryIdentity('git', $owner, $name, $task->workspace),
            $bloomWorkspaceId,
            $task->workspace,
            $branch,
            $baseSha,
            $task->paths,
            [$task->test_path],
            [new AcceptanceTest(Str::uuid()->toString(), $task->test_path, $task->test_digest)],
            (int) config('molly.max_attempts', 3),
            VerifierPolicyMap::defaults(),
            ExecutionTargetRequest::local('Bloom selected the existing local workspace.'),
            ApprovalRequirements::defaults(),
        );
        $this->write($task->workspace, $contract);

        return $contract;
    }

    private function write(string $workspace, TaskContract $contract): void
    {
        $root = (new Workspace($workspace))->path;
        $path = Directory::molly($root, 'bloom-contract.json');
        Directory::ensure(dirname($path), 0700);
        $mask = umask(0077);
        try {
            if (file_put_contents($path, $contract->toJson(), LOCK_EX) === false) {
                throw new RuntimeException('CONTRACT_UNWRITABLE: Molly could not write the Bloom task contract.');
            }
        } finally {
            umask($mask);
        }
    }
}

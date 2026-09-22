<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Sifrious\Molly\Classification\ClassifyRunEvidence;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Execution\Sandbox;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\RunStopped;
use Sifrious\Molly\Verification\FalseGreenVerifier;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\BindWorkspaceReference;
use Throwable;

class RunTask
{
    public function __construct(
        private GenerateChanges $generate,
        private VerifyChanges $verify,
        private ReviewChanges $review,
        private MeasureComplexity $measure,
        private EvaluateChanges $evaluate,
        private DecideRunCompletion $decideCompletion,
        private ClassifyRunEvidence $classify,
        private CaptureComponentPreview $preview,
        private RecordLifecycleEvent $lifecycle,
        private RecordVerificationReceipts $receipts,
        private FalseGreenVerifier $falseGreen,
        private Sandbox $sandbox,
        private ResolveEffectiveRunConfig $resolveEffectiveRunConfig,
        private BindWorkspaceReference $bindWorkspaceReference,
    ) {}

    /** @param list<string> $paths */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, ?Closure $progress = null, ?string $taskId = null, ?Closure $shouldStop = null, ?array $previousAttempt = null): Run
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        $files = new Workspace($workspace);
        $this->sandbox->refuseSafeWorkflow();
        $task = $taskId === null ? null : Task::find($taskId);
        $allowTestEdits = (bool) ($task?->allow_test_edits);
        $paths = $files->taskPaths($paths, $testPath, $allowTestEdits);
        $testDigest = is_string($task?->test_digest) ? $task->test_digest : $files->testDigest($testPath);
        if (! $allowTestEdits) {
            if ($testDigest === null) {
                throw new RuntimeException('PROTECTED_TEST_MISSING: Create and approve the required Pest test before the implementation turn.');
            }
            $files->assertProtectedTestUnchanged($testPath, $testDigest);
        }

        return $files->exclusively(function (string $workspaceLease) use ($files, $paths, $prompt, $testPath, $progress, $taskId, $shouldStop, $previousAttempt, $allowTestEdits, $testDigest): Run {
            $before = $files->read($paths);
            // Refresh the task once under the lease; outer load stays for safe pre-lease digest/path prep.
            $taskRow = $taskId === null ? null : Task::find($taskId);
            $overrides = is_array($taskRow?->context_snapshot['settings_overrides'] ?? null)
                ? $taskRow->context_snapshot['settings_overrides']
                : [];
            $effectiveConfig = $this->resolveEffectiveRunConfig->handle($overrides);
            $identity = $this->runIdentity($taskRow, $files->path);
            $run = Run::create([
                'prompt' => $prompt,
                'task_id' => $taskId,
                'workspace' => $files->path,
                'project_id' => $identity['project_id'],
                'workspace_id' => $identity['workspace_id'],
                'repository_id' => $identity['repository_id'],
                'repository_remote_identity' => $identity['repository_remote_identity'],
                'checkout_id' => $identity['checkout_id'],
                'checkout_kind' => $identity['checkout_kind'],
                'base_sha' => $identity['base_sha'],
                'branch' => $identity['branch'],
                'bloom_workspace_id' => $identity['bloom_workspace_id'],
                'identity_status' => $identity['identity_status'],
                'status' => 'running',
                'effective_config' => $effectiveConfig,
                'report' => $this->initialReport($files, $before, $paths, $testPath, $testDigest, $allowTestEdits, $taskRow),
            ]);

            return $this->execute($run, $files, $before, $testPath, $progress, $shouldStop, $workspaceLease, $previousAttempt, $allowTestEdits, $testDigest, $taskId);
        });
    }

    /** @param array<string, ?string> $before */
    private function execute(Run $run, Workspace $workspace, array $before, string $testPath, ?Closure $progress, ?Closure $shouldStop, string $workspaceLease, ?array $previousAttempt, bool $allowTestEdits, ?string $testDigest, ?string $taskId): Run
    {
        $report = $run->report;
        $evidence = storage_path('molly/'.$run->id);
        $recordProgress = function (string $message) use ($run, &$report, $progress): void {
            $report['phase'] = $message;
            $run->update(['report' => $report]);
            $progress?->__invoke($message);
        };

        try {
            File::ensureDirectoryExists($evidence, 0700);
            $this->checkpoint($shouldStop, $recordProgress, 'Measuring complexity before changes');
            $report['complexity_before'] = $this->measure->handle($workspace->path, $evidence.'/before');
            $this->requireMeasurements($report['complexity_before']);

            $this->record($workspace->path, LifecycleEventType::DispatchRequested, $taskId, $run->id, [
                'target' => 'local',
            ]);
            $this->record($workspace->path, LifecycleEventType::AgentStarted, $taskId, $run->id);
            $this->checkpoint($shouldStop, $recordProgress, 'Writing the selected files with '.(config('molly.agent', 'ollama') === 'amp' ? 'Amp' : 'Ollama'));
            $proposal = $this->generate->handle($run->prompt, $before, $testPath, $previousAttempt, $allowTestEdits, $testDigest);
            $this->record($workspace->path, LifecycleEventType::ProposalReceived, $taskId, $run->id);

            $this->checkpoint($shouldStop, $recordProgress, 'Applying the proposed changes');
            if (! $allowTestEdits && is_string($testDigest)) {
                $workspace->assertProtectedTestUnchanged($testPath, $testDigest);
            }
            $this->applyProposal($workspace, $proposal['files'], $before, $evidence);
            $after = $workspace->read(array_keys($before));
            if (! $allowTestEdits && is_string($testDigest)) {
                $workspace->assertProtectedTestUnchanged($testPath, $testDigest);
            }
            $report['summary'] = $proposal['summary'];
            $report['changes'] = $workspace->changes($before, $after);
            $run->update(['report' => $report]);

            if ($report['changes'] === []) {
                $this->record($workspace->path, LifecycleEventType::EditsRejected, $taskId, $run->id, ['reason' => 'NO_CHANGES']);
                throw new RuntimeException('NO_CHANGES: The agent returned no changes. The task was not verified as new work.');
            }
            $this->record($workspace->path, LifecycleEventType::EditsAccepted, $taskId, $run->id);

            if (! is_bool(config('molly.parallel_checks', true))) {
                throw new RuntimeException('PARALLEL_CONFIG_INVALID: Set molly.parallel_checks to true or false.');
            }

            $this->record($workspace->path, LifecycleEventType::VerificationStarted, $taskId, $run->id);
            if (config('molly.parallel_checks', true)) {
                $this->checkpoint($shouldStop, $recordProgress, 'Running Pest and Tarpit review in parallel');
                $report['mode'] = 'parallel';
                $results = $this->evaluate->handle($run->prompt, $workspace->path, $before, $after, $testPath, $evidence, $shouldStop, $workspaceLease, function (array $branches) use ($run, &$report): void {
                    $report['branches'] = $branches;
                    $run->update(['report' => $report]);
                });
                $report['verification'] = $results['verification'];
                $report['review'] = $results['review'];
                $report['branches'] = $results['branches'];
            } else {
                $report['mode'] = 'serial';
                $this->checkpoint($shouldStop, $recordProgress, 'Running the required Pest tests');
                $report['verification'] = $this->verify->handle($workspace->path, $testPath, $evidence);
                $run->update(['report' => $report]);

                $this->checkpoint($shouldStop, $recordProgress, 'Reviewing complexity with the seven Tarpit checks');
                $report['review'] = $this->review->handle($run->prompt, $before, $after);
            }
            $run->update(['report' => $report]);

            $this->checkpoint($shouldStop, $recordProgress, 'Measuring complexity after changes');
            $report['complexity_after'] = $this->measure->handle($workspace->path, $evidence.'/after');
            $this->requireMeasurements($report['complexity_after']);

            if ($workspace->read(array_keys($before)) !== $after) {
                throw new RuntimeException('WORKSPACE_CHANGED: Files changed after implementation. Run verification again.');
            }
            if (! $allowTestEdits && is_string($testDigest)) {
                $workspace->assertProtectedTestUnchanged($testPath, $testDigest);
            }

            if ((bool) config('molly.false_green.enabled', false)) {
                $this->checkpoint($shouldStop, $recordProgress, 'Probing for false-green Pest results');
                $report['false_green'] = $this->falseGreen->handle(
                    $workspace->path,
                    $testPath,
                    array_keys($before),
                    $evidence.'/false-green',
                );
                if ($workspace->read(array_keys($before)) !== $after) {
                    throw new RuntimeException('WORKSPACE_CHANGED: False-green probes left the workspace dirty.');
                }
            }

            $decision = $this->decideCompletion->handle($report, $after);
            $this->checkpoint($shouldStop, null, 'Completing the run');
            $this->persistSuccessfulCompletion($run, $report, $decision, $workspace, $before, $after);
        } catch (Throwable $exception) {
            $this->persistTerminatedFinalization($run, $report, $exception, $workspace, $before);
        }

        return $run->fresh();
    }

    /**
     * @param  list<array{path: string, content: string}>  $edits
     * @param  array<string, ?string>  $before
     */
    /**
     * @param  array<string, mixed>  $report
     * @param  array{completed: bool, outcomes: mixed, blockers: mixed}  $decision
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $after
     */
    private function persistSuccessfulCompletion(Run $run, array &$report, array $decision, Workspace $workspace, array $before, array $after): void
    {
        $report['verification_outcomes'] = $decision['outcomes'];
        $report['completion_blockers'] = $decision['blockers'];
        $report['verification_receipts'] = $this->receipts->handle($workspace->path, $run->id, $report);
        $classification = $this->classify->handle([
            'run_id' => $run->id,
            'verification' => $report['verification'] ?? [],
            'review' => $report['review'] ?? [],
        ]);
        $report['classification'] = [...$classification->toArray(), 'advisory' => true];
        $this->recordSnapshot($report, $workspace, $before, $after);
        $run->update(['status' => $decision['completed'] ? 'completed' : 'failed', 'report' => $report]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, ?string>  $before
     */
    private function persistTerminatedFinalization(Run $run, array &$report, Throwable $exception, Workspace $workspace, array $before): void
    {
        $report['error'] = $exception->getMessage();
        try {
            $observed = $workspace->read(array_keys($before));
            $report['changes'] = $workspace->changes($before, $observed);
            $this->recordSnapshot($report, $workspace, $before, $observed);
        } catch (Throwable $captureFailure) {
            $report['changes_unavailable'] = true;
            $report['snapshots']['after'] = ['status' => 'unavailable', 'reason' => 'SNAPSHOT_CAPTURE_FAILED: '.$captureFailure->getMessage()];
            $report['components'] = ['status' => 'unavailable', 'reason' => 'The final workspace could not be read. Component changes are unknown.'];
        }
        try {
            $decision = $this->decideCompletion->forTerminated($report);
            $report['verification_outcomes'] = $decision['outcomes'];
            $report['completion_blockers'] = $decision['blockers'];
            $report['verification_receipts'] = $this->receipts->handle($workspace->path, $run->id, $report);
            $report['terminated_before_completion'] = true;
        } catch (Throwable $receiptFailure) {
            $report['receipt_error'] = $receiptFailure->getMessage();
        }
        $run->update(['status' => $exception instanceof RunStopped ? 'stopped' : 'failed', 'report' => $report]);
    }

    private function applyProposal(Workspace $workspace, array $edits, array $before, string $evidence): void
    {
        $sandbox = $this->sandbox;
        if ($sandbox->available() && ! $sandbox->allowUnsafe()) {
            $sandbox->apply($workspace->path, $edits, array_keys($before), $evidence);

            return;
        }

        $workspace->apply($edits, $before);
    }

    /**
     * @param  array<string, ?string>  $contents
     * @return array<string, mixed>
     */
    private function withPreview(Workspace $workspace, array $contents, string $phase): array
    {
        $snapshot = $workspace->snapshot($contents);
        $snapshot['preview'] = $this->preview->handle($workspace->path, $contents, $phase);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $contents
     */
    private function recordSnapshot(array &$report, Workspace $workspace, array $before, array $contents): void
    {
        $report['snapshots']['after'] = $this->withPreview($workspace, $contents, 'after');
        $report['components'] = ['status' => 'compared', 'changes' => $workspace->componentChanges($report['changes'] ?? [], $before)];
    }

    /** @param  array<string, mixed>  $payload */
    private function record(string $workspace, LifecycleEventType $type, ?string $taskId, string $runId, array $payload = []): void
    {
        if ($taskId === null) {
            return;
        }

        $this->lifecycle->handle($workspace, $type, $taskId, $runId, $payload);
    }

    private function checkpoint(?Closure $shouldStop, ?Closure $progress, string $message): void
    {
        if ($shouldStop?->__invoke()) {
            throw new RunStopped('RUN_STOPPED: Molly stopped at an execution boundary. Applied edits remain available for review.');
        }
        $progress?->__invoke($message);
        if ($shouldStop?->__invoke()) {
            throw new RunStopped('RUN_STOPPED: Molly stopped at an execution boundary. Applied edits remain available for review.');
        }
    }

    /** @param array<string, mixed> $measurement */
    private function requireMeasurements(array $measurement): void
    {
        if (! in_array($measurement['status'] ?? null, ['ok', 'skipped'], true)) {
            throw new RuntimeException('CLEVER_UNAVAILABLE: '.($measurement['reason'] ?? 'Clever did not produce a usable report.'));
        }
    }

    /**
     * @param  array<string, ?string>  $before
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function initialReport(Workspace $files, array $before, array $paths, string $testPath, ?string $testDigest, bool $allowTestEdits, ?Task $taskRow): array
    {
        return [
            'scope' => $paths,
            'protected_test' => [
                'path' => $testPath,
                'digest' => $testDigest,
                'writable' => $allowTestEdits,
            ],
            'provider' => config('molly.agent', 'ollama'),
            'model' => config('molly.agent', 'ollama') === 'ollama' ? config('molly.model') : null,
            'snapshots' => [
                'task_creation' => $taskRow?->context_snapshot,
                'before' => $this->withPreview($files, $before, 'before'),
                'after' => ['status' => 'not_captured', 'reason' => 'The run has not captured the final workspace.'],
            ],
            'components' => ['status' => 'not_compared', 'reason' => 'The run has not captured the final workspace.'],
        ];
    }

    /** @return array<string, mixed> */
    private function runIdentity(?Task $task, string $path): array
    {
        if ($task !== null && $task->identity_status === 'bound' && is_string($task->workspace_id) && $task->workspace_id !== '') {
            return [
                'project_id' => $task->project_id,
                'workspace_id' => $task->workspace_id,
                'repository_id' => $task->repository_id,
                'repository_remote_identity' => $task->repository_remote_identity,
                'checkout_id' => $task->checkout_id,
                'checkout_kind' => $task->checkout_kind,
                'base_sha' => $task->base_sha,
                'branch' => $task->branch,
                'bloom_workspace_id' => $task->bloom_workspace_id,
                'identity_status' => 'bound',
            ];
        }

        $reference = $this->bindWorkspaceReference->handle($path);
        $reference->assertAvailableForExecution();

        return [
            'project_id' => $reference->project->id,
            'workspace_id' => $reference->workspace->id,
            'repository_id' => $reference->repositoryId,
            'repository_remote_identity' => $reference->repositoryRemoteIdentity,
            'checkout_id' => $reference->checkoutId,
            'checkout_kind' => $reference->checkoutKind,
            'base_sha' => $reference->head->sha,
            'branch' => $reference->branch,
            'bloom_workspace_id' => $reference->bloomWorkspaceId,
            'identity_status' => 'bound',
        ];
    }
}

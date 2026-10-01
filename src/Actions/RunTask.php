<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Sifrious\Molly\Classification\ClassifyRunEvidence;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Execution\Sandbox;
use Sifrious\Molly\Knowledge\LivewireLayout;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\RunStopped;
use Sifrious\Molly\Verification\FalseGreenVerifier;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\BindWorkspaceReference;
use Sifrious\Molly\Workspace\Directory;
use Sifrious\Molly\Workspace\GitBinary;
use Sifrious\Molly\Workspace\ObserveCheckout;
use Symfony\Component\Process\Process;
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
        private FingerprintRunFailure $fingerprint,
        private RecordModelIdentity $modelIdentity,
        private ObserveCheckout $observe,
        private RecordRedBaseline $redBaseline,
    ) {}

    /**
     * @param  list<string>  $paths
     * @param  Closure|null  $heartbeat  Renews the caller's task lease at each checkpoint.
     * @param  Closure|null  $prepared  Runs once the preconditions pass and the run is saved.
     * @param  array<string, mixed>|null  $executionTarget  The Orb evidence when the run executes on an Orb.
     */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, ?Closure $progress = null, ?string $taskId = null, ?Closure $shouldStop = null, ?array $previousAttempt = null, ?Closure $heartbeat = null, ?Closure $prepared = null, ?array $executionTarget = null): Run
    {
        $files = new Workspace($workspace);
        $task = $taskId === null ? null : Task::find($taskId);
        [$paths, $testDigest] = $this->refuseUnready($prompt, $files, $paths, $testPath, $task);
        $allowTestEdits = (bool) ($task?->allow_test_edits);

        return $files->exclusively(function (string $workspaceLease) use ($files, $paths, $prompt, $testPath, $progress, $taskId, $shouldStop, $previousAttempt, $allowTestEdits, $testDigest, $heartbeat, $prepared, $executionTarget): Run {
            $before = $files->read($paths);
            // Refresh the task once under the lease; outer load stays for safe pre-lease digest/path prep.
            $taskRow = $taskId === null ? null : Task::find($taskId);
            $overrides = is_array($taskRow?->context_snapshot['settings_overrides'] ?? null)
                ? $taskRow->context_snapshot['settings_overrides']
                : [];
            if (is_array($executionTarget['orb'] ?? null)) {
                // The snapshot records the runtime and model the Orb supplies, not the global settings.
                $overrides = [...$overrides, 'agent' => $executionTarget['orb']['runtime'], 'model' => $executionTarget['orb']['model']];
            }
            $effectiveConfig = $this->resolveEffectiveRunConfig->handle($overrides);
            $identity = $this->runIdentity($taskRow, $files->path);
            if ($executionTarget !== null) {
                $executionTarget = [...$executionTarget, 'starting_revision' => $identity['base_sha'], 'started_at' => now()->toIso8601String()];
            }
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
                'report' => [
                    ...$this->initialReport($files, $before, $paths, $testPath, $testDigest, $allowTestEdits, $taskRow),
                    ...($executionTarget === null ? [] : ['execution_target' => $executionTarget]),
                ],
            ]);
            if ($prepared !== null) {
                $prepared($run);
            }

            return $this->execute($run, $files, $before, $testPath, $progress, $shouldStop, $workspaceLease, $previousAttempt, $allowTestEdits, $testDigest, $taskId, $heartbeat);
        });
    }

    /**
     * Check a run's preconditions without writing anything, in this order: the task's own
     * inputs, the git executable, a committed checkout, then the sandbox. A mistyped test path
     * is reported as such and not hidden behind SANDBOX_UNAVAILABLE.
     *
     * @param  list<string>  $paths
     * @return array{0: list<string>, 1: ?string} the normalized write paths and the protected test digest
     */
    public function refuseUnready(string $prompt, Workspace $files, array $paths, string $testPath, ?Task $task): array
    {
        if ($task !== null) {
            app(GuardSeamRevision::class)->beforeImplementation($task);
        }
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }
        if (($blocked = $task?->redBaselineError()) !== null) {
            throw new RuntimeException($blocked);
        }
        $allowTestEdits = (bool) ($task?->allow_test_edits);
        $paths = $files->taskPaths($paths, $testPath, $allowTestEdits);
        $testDigest = is_string($task?->test_digest) ? $task->test_digest : $files->testDigest($testPath);
        if (! $allowTestEdits) {
            if ($testDigest === null) {
                throw $files->missingProtectedTest($testPath);
            }
            $files->assertProtectedTestUnchanged($testPath, $testDigest);
        }
        GitBinary::require();
        $this->observe->requireCommit($files->path);
        $this->sandbox->refuseSafeWorkflow();

        return [$paths, $testDigest];
    }

    /** @param array<string, ?string> $before */
    private function execute(Run $run, Workspace $workspace, array $before, string $testPath, ?Closure $progress, ?Closure $shouldStop, string $workspaceLease, ?array $previousAttempt, bool $allowTestEdits, ?string $testDigest, ?string $taskId, ?Closure $heartbeat): Run
    {
        $report = $run->report;
        $evidence = storage_path('molly/'.$run->id);
        $recordProgress = function (string $message) use ($run, &$report, $progress): void {
            $report['phase'] = $message;
            $run->update(['report' => $report]);
            $progress?->__invoke($message);
        };

        try {
            Directory::ensure($evidence, 0700);
            $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Measuring complexity before changes');
            // Clever is advisory: an unavailable or failed measurement is recorded with its
            // status and reason, never as passing, and the run continues.
            $report['complexity_before'] = $this->measure->handle($workspace->path, $evidence.'/before');

            $this->record($workspace->path, LifecycleEventType::DispatchRequested, $taskId, $run->id, $this->dispatchPayload($report));
            $this->record($workspace->path, LifecycleEventType::AgentStarted, $taskId, $run->id);
            $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Writing the selected files with '.(config('molly.agent', 'ollama') === 'amp' ? 'Amp' : 'Ollama'));
            $generationStarted = hrtime(true);
            try {
                $proposal = $this->generate->handle($run->prompt, $before, $testPath, $previousAttempt, $allowTestEdits, $testDigest, $allowTestEdits ? $this->pestConfiguration($workspace, $before) : [], LivewireLayout::forWorkspace($workspace->path)->toArray());
            } finally {
                $report['model_identity'] = $this->modelIdentity->handle(intdiv(hrtime(true) - $generationStarted, 1_000_000));
                $run->update(['report' => $report]);
            }
            $this->record($workspace->path, LifecycleEventType::ProposalReceived, $taskId, $run->id);

            $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Applying the proposed changes');
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
                $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Running Pest and Tarpit review in parallel');
                $report['mode'] = 'parallel';
                $results = $this->evaluate->handle($run->prompt, $workspace->path, $before, $after, $testPath, $evidence, $shouldStop, $workspaceLease, function (array $branches) use ($run, &$report): void {
                    $report['branches'] = $branches;
                    $run->update(['report' => $report]);
                }, $report['execution_target']['target_id'] ?? 'local');
                $report['verification'] = $results['verification'];
                $report['review'] = $results['review'];
                $report['branches'] = $results['branches'];
            } else {
                $report['mode'] = 'serial';
                $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Running the required Pest tests');
                $report['verification'] = $this->verify->handle($workspace->path, $testPath, $evidence);
                $run->update(['report' => $report]);

                $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Reviewing complexity with the seven Tarpit checks');
                $report['review'] = $this->review->handle($run->prompt, $before, $after);
            }
            if ($allowTestEdits) {
                $report['authored_test'] = $this->checkAuthoredTest(is_array($report['verification'] ?? null) ? $report['verification'] : [], $workspace->path, $testPath);
            }
            $run->update(['report' => $report]);

            $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Measuring complexity after changes');
            $report['complexity_after'] = $this->measure->handle($workspace->path, $evidence.'/after');

            if ($workspace->read(array_keys($before)) !== $after) {
                throw new RuntimeException('WORKSPACE_CHANGED: Files changed after implementation. Run verification again.');
            }
            if (! $allowTestEdits && is_string($testDigest)) {
                $workspace->assertProtectedTestUnchanged($testPath, $testDigest);
            }

            if ((bool) config('molly.false_green.enabled', false)) {
                $this->checkpoint($heartbeat, $shouldStop, $recordProgress, 'Probing for false-green Pest results');
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
            $this->checkpoint($heartbeat, $shouldStop, null, 'Completing the run');
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
        $this->recordWorktreeDiff($report, $workspace, $run->id);
        $report['verification_receipts'] = $this->receipts->handle($workspace->path, $run->id, $report);
        $classification = $this->classify->handle([
            'run_id' => $run->id,
            'verification' => $report['verification'] ?? [],
            'review' => $report['review'] ?? [],
        ]);
        $report['classification'] = [...$classification->toArray(), 'advisory' => true];
        $this->recordSnapshot($report, $workspace, $before, $after);
        if (! $decision['completed']) {
            $report['failure_fingerprint'] = $this->fingerprint->handle($report);
        }
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
            $this->recordWorktreeDiff($report, $workspace, $run->id);
            $report['verification_receipts'] = $this->receipts->handle($workspace->path, $run->id, $report);
            $report['terminated_before_completion'] = true;
        } catch (Throwable $receiptFailure) {
            $report['receipt_error'] = $receiptFailure->getMessage();
        }
        if (! $exception instanceof RunStopped) {
            $report['failure_fingerprint'] = $this->fingerprint->handle($report);
        }
        $run->update(['status' => $exception instanceof RunStopped ? 'stopped' : 'failed', 'report' => $report]);
    }

    private function applyProposal(Workspace $workspace, array $edits, array $before, string $evidence): void
    {
        $sandbox = $this->sandbox;
        if ($sandbox->available() && ! $sandbox->allowUnsafe()) {
            // The sandboxed writer reports only that it failed, so name an unwritable file first.
            $workspace->assertWritable(array_column($edits, 'path'));
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

    /**
     * Where the run was dispatched, for the dispatch_requested lifecycle event.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function dispatchPayload(array $report): array
    {
        $target = $report['execution_target'] ?? null;
        if (! is_array($target) || ($target['kind'] ?? null) !== 'orb') {
            return ['target' => 'local'];
        }

        return [
            'target' => 'orb',
            'orb_id' => $target['target_id'] ?? null,
            'orb_name' => $target['orb']['name'] ?? null,
            'device' => $target['orb']['device'] ?? null,
            'runtime' => $target['orb']['runtime'] ?? null,
            'model' => $target['orb']['model'] ?? null,
            'capabilities' => $target['capabilities'] ?? [],
            'repository' => $target['repository']['path'] ?? null,
            'worktree' => $target['worktree'] ?? null,
            'starting_revision' => $target['starting_revision'] ?? null,
            'prompt_sha256' => $target['prompt_sha256'] ?? null,
            'selection_reason' => $target['selection_reason'] ?? null,
        ];
    }

    /**
     * For a run on an Orb, save the difference between the starting revision and the worktree
     * for the task's files as a patch beside the run's evidence, and record its path, size, and
     * SHA-256 digest. A new file is compared with an empty one. The receipts cover the digest.
     *
     * @param  array<string, mixed>  $report
     */
    private function recordWorktreeDiff(array &$report, Workspace $workspace, string $runId): void
    {
        $target = $report['execution_target'] ?? null;
        if (! is_array($target) || ($target['kind'] ?? null) !== 'orb') {
            return;
        }
        $base = $target['starting_revision'] ?? null;
        $paths = array_values(array_unique(array_filter([...($report['scope'] ?? []), $report['protected_test']['path'] ?? null], is_string(...))));
        sort($paths, SORT_STRING);
        try {
            if (! is_string($base) || preg_match('/\A[0-9a-f]{40}\z/', $base) !== 1) {
                throw new RuntimeException('The run has no starting revision.');
            }
            $patch = '';
            foreach ($paths as $path) {
                $tracked = $this->git($workspace->path, ['cat-file', '-e', $base.':'.$path])->isSuccessful();
                $diff = $tracked
                    ? $this->git($workspace->path, ['diff', '--no-color', '--no-ext-diff', '--binary', $base, '--', $path])
                    : (is_file($workspace->path.'/'.$path) ? $this->git($workspace->path, ['diff', '--no-color', '--no-ext-diff', '--binary', '--no-index', '--', '/dev/null', $path]) : null);
                if ($diff !== null && ! in_array($diff->getExitCode(), [0, 1], true)) {
                    throw new RuntimeException('git diff failed for '.$path.': '.trim($diff->getErrorOutput()));
                }
                $patch .= $diff?->getOutput() ?? '';
            }
            $file = storage_path('molly/'.$runId.'/worktree.patch');
            Directory::ensure(dirname($file), 0700);
            File::put($file, $patch);
            @chmod($file, 0600);
            $report['execution_target']['diff'] = [
                'status' => 'captured',
                'base' => $base,
                'paths' => $paths,
                'path' => $file,
                'bytes' => strlen($patch),
                'sha256' => hash('sha256', $patch),
            ];
        } catch (Throwable $exception) {
            $report['execution_target']['diff'] = ['status' => 'unavailable', 'reason' => 'WORKTREE_DIFF_UNAVAILABLE: '.$exception->getMessage()];
        }
    }

    /** @param  list<string>  $arguments */
    private function git(string $directory, array $arguments): Process
    {
        $process = new Process(['git', '-C', $directory, ...$arguments]);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /** @param  array<string, mixed>  $payload */
    private function record(string $workspace, LifecycleEventType $type, ?string $taskId, string $runId, array $payload = []): void
    {
        if ($taskId === null) {
            return;
        }

        $this->lifecycle->handle($workspace, $type, $taskId, $runId, $payload);
    }

    private function checkpoint(?Closure $heartbeat, ?Closure $shouldStop, ?Closure $progress, string $message): void
    {
        $heartbeat?->__invoke();
        if ($shouldStop?->__invoke()) {
            throw new RunStopped('RUN_STOPPED: Molly stopped at an execution boundary. Applied edits remain available for review.');
        }
        $progress?->__invoke($message);
        if ($shouldStop?->__invoke()) {
            throw new RunStopped('RUN_STOPPED: Molly stopped at an execution boundary. Applied edits remain available for review.');
        }
    }

    /**
     * Classify the test an authoring run wrote with the RED baseline
     * classifier, so a test that cannot run is caught before a lock.
     *
     * @param  array<string, mixed>  $verification
     * @return array<string, mixed>
     */
    private function checkAuthoredTest(array $verification, string $workspace, string $testPath): array
    {
        return [
            ...$this->redBaseline->classify($verification, $workspace, $testPath),
            'test_path' => $testPath,
            'tests' => $verification['tests'] ?? 0,
            'failures' => $verification['failures'] ?? 0,
            'errors' => $verification['errors'] ?? 0,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * tests/Pest.php as read-only context for a test-authoring run, so the
     * model sees which TestCase and traits apply to every test. The content
     * is null when the file does not exist. The file is left out when the
     * run may write it or Molly cannot read it.
     *
     * @param  array<string, ?string>  $before
     * @return array<string, ?string>
     */
    private function pestConfiguration(Workspace $workspace, array $before): array
    {
        if (array_key_exists('tests/Pest.php', $before)) {
            return [];
        }
        try {
            return $workspace->readProtectedTest('tests/Pest.php');
        } catch (Throwable) {
            return [];
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
            'red_baseline' => $taskRow?->source['test_lock']['red_baseline'] ?? null,
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

    /**
     * Stored task IDs are kept as they are. The run records the revision and branch
     * observed when it starts, so each attempt names the commit it ran against.
     *
     * @return array<string, mixed>
     */
    private function runIdentity(?Task $task, string $path): array
    {
        $reference = $this->bindWorkspaceReference->handle($path);
        $reference->assertAvailableForExecution();

        if ($task !== null && $task->identity_status === 'bound' && is_string($task->workspace_id) && $task->workspace_id !== '') {
            return [
                'project_id' => $task->project_id,
                'workspace_id' => $task->workspace_id,
                'repository_id' => $task->repository_id,
                'repository_remote_identity' => $task->repository_remote_identity,
                'checkout_id' => $task->checkout_id,
                'checkout_kind' => $task->checkout_kind,
                'base_sha' => $reference->head->sha,
                'branch' => $reference->branch,
                'bloom_workspace_id' => $task->bloom_workspace_id,
                'identity_status' => 'bound',
            ];
        }

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

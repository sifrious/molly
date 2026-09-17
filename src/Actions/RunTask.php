<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\RunStopped;
use Sifrious\Molly\Workspace;
use Throwable;

class RunTask
{
    public function __construct(
        private GenerateChanges $generate,
        private VerifyChanges $verify,
        private ReviewChanges $review,
        private MeasureComplexity $measure,
        private EvaluateChanges $evaluate,
    ) {}

    /** @param list<string> $paths */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, ?Closure $progress = null, ?string $taskId = null, ?Closure $shouldStop = null, ?array $previousAttempt = null): Run
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        $files = new Workspace($workspace);

        $paths = $files->taskPaths($paths, $testPath);

        return $files->exclusively(function (string $workspaceLease) use ($files, $paths, $prompt, $testPath, $progress, $taskId, $shouldStop, $previousAttempt): Run {
            $before = $files->read($paths);
            $run = Run::create([
                'prompt' => $prompt,
                'task_id' => $taskId,
                'workspace' => $files->path,
                'status' => 'running',
                'report' => ['scope' => $paths, 'provider' => 'ollama', 'model' => config('molly.model')],
            ]);

            return $this->execute($run, $files, $before, $testPath, $progress, $shouldStop, $workspaceLease, $previousAttempt);
        });
    }

    /** @param array<string, ?string> $before */
    private function execute(Run $run, Workspace $workspace, array $before, string $testPath, ?Closure $progress, ?Closure $shouldStop, string $workspaceLease, ?array $previousAttempt): Run
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

            $this->checkpoint($shouldStop, $recordProgress, 'Writing the selected files with Ollama');
            $proposal = $previousAttempt === null
                ? $this->generate->handle($run->prompt, $before, $testPath)
                : $this->generate->handle($run->prompt, $before, $testPath, $previousAttempt);
            $this->checkpoint($shouldStop, $recordProgress, 'Applying the proposed changes');
            $workspace->apply($proposal['files'], $before);
            $after = $workspace->read(array_keys($before));
            $report['summary'] = $proposal['summary'];
            $report['changes'] = $workspace->changes($before, $after);
            $run->update(['report' => $report]);

            if ($report['changes'] === []) {
                throw new RuntimeException('NO_CHANGES: The agent returned no changes. The task was not verified as new work.');
            }

            if (! is_bool(config('molly.parallel_checks', true))) {
                throw new RuntimeException('PARALLEL_CONFIG_INVALID: Set molly.parallel_checks to true or false.');
            }
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

            $completed = ($report['verification']['status'] ?? null) === 'passed'
                && $this->review->passed($report['review'], $after)
                && ($report['mode'] !== 'parallel' || $this->branchesPassed($report['branches']));

            $this->checkpoint($shouldStop, null, 'Completing the run');
            $run->update(['status' => $completed ? 'completed' : 'failed', 'report' => $report]);
        } catch (Throwable $exception) {
            $report['error'] = $exception->getMessage();
            try {
                $report['changes'] = $workspace->changes($before, $workspace->read(array_keys($before)));
            } catch (Throwable) {
                $report['changes_unavailable'] = true;
            }
            $run->update(['status' => $exception instanceof RunStopped ? 'stopped' : 'failed', 'report' => $report]);
        }

        return $run->fresh();
    }

    /** @param list<array<string, mixed>> $branches */
    private function branchesPassed(array $branches): bool
    {
        $kinds = array_column($branches, 'kind');
        sort($kinds);
        if (count($branches) !== 2 || $kinds !== ['review', 'verification']) {
            return false;
        }

        foreach ($branches as $branch) {
            if (($branch['status'] ?? null) !== 'passed' || empty($branch['result_ref']) || empty($branch['finished_at'])) {
                return false;
            }
        }

        return true;
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
}

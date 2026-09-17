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
    ) {}

    /** @param list<string> $paths */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, ?Closure $progress = null, ?string $taskId = null, ?Closure $shouldStop = null): Run
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        $files = new Workspace($workspace);

        if (! in_array($testPath, $paths, true) || ! str_starts_with($testPath, 'tests/') || ! str_ends_with($testPath, '.php')) {
            throw new RuntimeException('TEST_PATH_INVALID: Include the required PHP test file in --file and select it with --test.');
        }

        return $files->exclusively(function () use ($files, $paths, $prompt, $testPath, $progress, $taskId, $shouldStop): Run {
            $before = $files->read($paths);
            $run = Run::create([
                'prompt' => $prompt,
                'task_id' => $taskId,
                'workspace' => $files->path,
                'status' => 'running',
                'report' => ['scope' => $paths, 'provider' => 'ollama', 'model' => config('molly.model')],
            ]);

            return $this->execute($run, $files, $before, $testPath, $progress, $shouldStop);
        });
    }

    /** @param array<string, ?string> $before */
    private function execute(Run $run, Workspace $workspace, array $before, string $testPath, ?Closure $progress, ?Closure $shouldStop): Run
    {
        $report = $run->report;
        $evidence = storage_path('molly/'.$run->id);

        try {
            File::ensureDirectoryExists($evidence, 0700);
            $this->checkpoint($shouldStop, $progress, 'Measuring complexity before changes');
            $report['complexity_before'] = $this->measure->handle($workspace->path, $evidence.'/before');
            $this->requireMeasurements($report['complexity_before']);

            $this->checkpoint($shouldStop, $progress, 'Writing the selected files with Ollama');
            $proposal = $this->generate->handle($run->prompt, $before, $testPath);
            $this->checkpoint($shouldStop, $progress, 'Applying the proposed changes');
            $workspace->apply($proposal['files'], $before);
            $after = $workspace->read(array_keys($before));
            $report['summary'] = $proposal['summary'];
            $report['changes'] = $workspace->changes($before, $after);
            $run->update(['report' => $report]);

            if ($report['changes'] === []) {
                throw new RuntimeException('NO_CHANGES: The agent returned no changes. The task was not verified as new work.');
            }

            $this->checkpoint($shouldStop, $progress, 'Running the required Pest tests');
            $report['verification'] = $this->verify->handle($workspace->path, $testPath, $evidence);
            $run->update(['report' => $report]);

            $this->checkpoint($shouldStop, $progress, 'Reviewing complexity with the seven Tarpit checks');
            $report['review'] = $this->review->handle($run->prompt, $before, $after);
            $run->update(['report' => $report]);

            $this->checkpoint($shouldStop, $progress, 'Measuring complexity after changes');
            $report['complexity_after'] = $this->measure->handle($workspace->path, $evidence.'/after');
            $this->requireMeasurements($report['complexity_after']);

            if ($workspace->read(array_keys($before)) !== $after) {
                throw new RuntimeException('WORKSPACE_CHANGED: Files changed after implementation. Run verification again.');
            }

            $completed = ($report['verification']['status'] ?? null) === 'passed'
                && $this->reviewPassed($report['review']);

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

    /** @param array<string, mixed> $review */
    private function reviewPassed(array $review): bool
    {
        foreach (range('A', 'G') as $check) {
            if (! in_array($review['checks'][$check]['status'] ?? null, ['clean', 'findings'], true)
                || trim($review['checks'][$check]['evidence'] ?? '') === '') {
                return false;
            }
        }

        if (! isset($review['findings']) || ! is_array($review['findings'])) {
            return false;
        }

        return ! collect($review['findings'])->contains(fn (array $finding): bool => ($finding['severity'] ?? null) === 'blocking');
    }
}

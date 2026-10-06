<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Seams\PackFiles;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\StepResult;
use Sifrious\Molly\Workspace\Directory;

final class RecordSeamAttempt
{
    public function __construct(private RecordVerificationReceipts $receipts, private RecordSeamEvent $events,
        private SecretRedactor $redactor, private PackFiles $files) {}

    public function handle(SeamRevision $revision, Run $attempt, StepResult $result, string $directory, float $duration): array
    {
        $workspace = $revision->snapshot['workspace'];
        $data = $this->redactor->value($result->toArray(), $workspace);
        $data['duration_ms'] = $duration;
        $path = $directory.'/result.json';
        if (file_exists($path)) {
            throw new SeamError('EVENT_PERSIST_FAILED', 'An attempt cannot overwrite its terminal evidence.', $path);
        }
        $this->redactArtifacts($directory, $workspace);
        Directory::replaceFile($path, $this->files->canonical($data)."\n", 'EVENT_PERSIST_FAILED');
        $artifacts = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new SeamError('ARTIFACT_MISSING', 'Evidence must contain regular files.', $file->getPathname());
            }
            $artifacts[] = ['path' => $file->getPathname(), 'sha256' => hash_file('sha256', $file->getPathname())];
        }
        $step = $attempt->report['seam']['step_id'];
        $policy = $revision->snapshot['policies'][$step];
        $report = [...$attempt->report, 'seam' => [...$attempt->report['seam'], 'artifacts' => $artifacts],
            'verification_outcomes' => ['seam_'.$step => ['state' => $result->state->value, ...$policy]], 'step_result' => $data];
        $report['verification_receipts'] = $this->receipts->handle($workspace, $attempt->id, $report);
        $this->events->handle($revision, $attempt, LifecycleEventType::ArtifactRecorded, ['artifacts' => $artifacts]);
        $this->events->handle($revision, $attempt, LifecycleEventType::CheckRecorded, ['state' => $result->state->value, 'policy' => $policy, 'artifacts' => $artifacts]);
        $this->events->handle($revision, $attempt, $result->state->value === 'PASS' ? LifecycleEventType::CommandCompleted : LifecycleEventType::CommandFailed,
            ['state' => $result->state->value, 'duration_ms' => $duration, 'error' => $data['error']]);
        $attempt->update(['status' => $result->state->value === 'PASS' ? 'completed' : 'failed', 'report' => $report]);

        return [...$data, 'attempt_id' => $attempt->id, 'artifacts' => $artifacts];
    }

    public function verify(array $artifacts, string $workspace): void
    {
        if ($artifacts === []) {
            throw new SeamError('ARTIFACT_MISSING', 'No immutable evidence accompanies this result.', $workspace);
        }
        foreach ($artifacts as $artifact) {
            $prefix = rtrim($workspace, '/').'/.molly/';
            $path = $artifact['path'] ?? '';
            if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                throw new SeamError('ARTIFACT_MISSING', 'The evidence path is outside the task receipt store.', (string) $path);
            }
            Directory::molly($workspace, substr($path, strlen($prefix)));
            if (! is_file($path) || hash_file('sha256', $path) !== ($artifact['sha256'] ?? null)) {
                throw new SeamError('ARTIFACT_DIGEST_MISMATCH', 'Evidence changed or is missing. Run a new verification attempt.', $path);
            }
        }
    }

    private function redactArtifacts(string $directory, string $workspace): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile()) {
                throw new SeamError('ARTIFACT_MISSING', 'Evidence must contain regular files.', $file->getPathname());
            }
            Directory::replaceFile($file->getPathname(), $this->redactor->text(file_get_contents($file->getPathname()), $workspace), 'EVENT_PERSIST_FAILED');
        }
    }
}

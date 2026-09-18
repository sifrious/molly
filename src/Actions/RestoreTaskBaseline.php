<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

class RestoreTaskBaseline
{
    public function store(Task $task, array $files): void
    {
        $path = $this->path($task);
        File::ensureDirectoryExists(dirname($path), 0700);
        $payload = json_encode([
            'task_id' => $task->id,
            'workspace' => $task->workspace,
            'files' => $files,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('BASELINE_UNWRITABLE: Molly could not store the task baseline.');
        }
        chmod($path, 0600);
    }

    public function handle(Task $task): void
    {
        $path = $this->path($task);
        if (! is_file($path)) {
            throw new RuntimeException('BASELINE_MISSING: Retry needs the recorded workspace baseline. Accept the current files as a new baseline before retrying.');
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['task_id'] ?? null) !== $task->id || ! is_array($payload['files'] ?? null)) {
            throw new RuntimeException('BASELINE_INVALID: The recorded workspace baseline cannot be used.');
        }

        $workspace = new Workspace($task->workspace);
        foreach ($payload['files'] as $relative => $contents) {
            if (! is_string($relative) || (! is_string($contents) && $contents !== null)) {
                throw new RuntimeException('BASELINE_INVALID: The recorded workspace baseline cannot be used.');
            }
            $absolute = $workspace->path.'/'.$relative;
            if ($contents === null) {
                if (is_file($absolute) && ! File::delete($absolute)) {
                    throw new RuntimeException('BASELINE_RESTORE_FAILED: Molly could not restore '.$relative.'.');
                }

                continue;
            }
            File::ensureDirectoryExists(dirname($absolute));
            File::replace($absolute, $contents);
        }
    }

    private function path(Task $task): string
    {
        return $task->workspace.'/.molly/baselines/'.$task->id.'.json';
    }
}

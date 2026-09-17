<?php

namespace Sifrious\Molly\Console;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class TaskReport
{
    public function show(Task $task): void
    {
        note('Task '.$task->reference().' / '.$task->status);
        if ($task->nickname !== null) {
            note('Task ID: '.$task->id);
        }
        note($task->prompt);
        note('Workspace: '.$task->workspace);
        note('Required test: '.$task->test_path);
        table(['Allowed file'], array_map(fn (string $path): array => [$path], $task->paths));
        if ($task->status === 'running' && $task->stop_requested_at !== null) {
            note('Stop requested. Molly will stop at the next execution boundary. An active model request or test process may finish first.');
        }
        if ($task->relationLoaded('runs')) {
            if ($task->runs->isEmpty()) {
                note('No runs yet. Start this task with php artisan molly:start '.$task->reference().'.');
            } else {
                table(['Run', 'Status'], $task->runs->map(fn (Run $run): array => [$run->id, $run->status])->all());
                note('Read a run report with php artisan molly:show RUN_ID.');
            }
        }
    }
}

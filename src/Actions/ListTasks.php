<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Sifrious\Molly\Models\Task;

class ListTasks
{
    /** @return Collection<int, Task> */
    public function handle(int $limit = 20): Collection
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('TASK_LIMIT_INVALID: Choose a limit between 1 and 100.');
        }

        return Task::latest()->orderByDesc('id')->limit($limit)->get();
    }
}

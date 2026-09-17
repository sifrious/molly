<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Task;

class ShowTask
{
    public function handle(string $id): ?Task
    {
        return Task::with('runs')->find($id);
    }
}

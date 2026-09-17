<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Task;

class ShowTask
{
    public function handle(string $id): ?Task
    {
        return Task::findByReference($id)?->load('runs');
    }
}

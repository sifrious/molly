<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Sifrious\Molly\Models\Task;

class NameTask
{
    public function handle(string $reference, string $nickname): Task
    {
        $task = Task::findByReference($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $nickname = Task::validateNickname($nickname, $task->id);

        try {
            $task->update(['nickname' => $nickname]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('TASK_NAME_TAKEN: Another task already uses that name.', 0, $exception);
        }

        return $task->fresh();
    }
}

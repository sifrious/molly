<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Models\Task;

class QueueTask
{
    public function __construct(private ShowTask $show) {}

    public function handle(string $id, bool $retry = false): Task
    {
        $task = $this->show->handle($id) ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new RuntimeException('Choose a database, Redis, SQS, or Beanstalkd queue connection and start a queue worker before running tasks.');
        }
        if (in_array($driver, ['database', 'redis', 'beanstalkd'], true) && (int) config('queue.connections.'.config('queue.default').'.retry_after', 0) <= 3600) {
            throw new RuntimeException('Set the queue connection retry_after above 3600 seconds so a task cannot be reserved again while its worker is running.');
        }
        StartSavedTask::dispatch($task->id, $retry);

        return $task;
    }
}

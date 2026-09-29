<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Execution\SelectExecutionTarget;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Models\Orb;
use Sifrious\Molly\Models\Task;
use Throwable;

class QueueTask
{
    public function __construct(private ShowTask $show, private LocalAgentBus $bus, private SelectExecutionTarget $targets) {}

    /**
     * Queue a start or retry. With an Orb request, Molly places the task on an Orb now, which
     * reserves that Orb, and sends the job to the Orb's own queue, which the Orb's worker reads.
     */
    public function handle(string $id, bool $retry = false, ?ExecutionTargetRequest $target = null): Task
    {
        $task = $this->show->handle($id) ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $connection = (string) config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new RuntimeException('QUEUE_DRIVER_UNSUPPORTED: The '.$connection.' queue connection uses the '.(is_string($driver) ? $driver : 'unknown').' driver. Choose a database, Redis, SQS, or Beanstalkd queue connection and start a queue worker before running tasks.');
        }
        $retryAfter = (int) config('queue.connections.'.$connection.'.retry_after', 0);
        if (in_array($driver, ['database', 'redis', 'beanstalkd'], true) && $retryAfter <= 3600) {
            throw new RuntimeException('QUEUE_RETRY_AFTER_TOO_SHORT: Set the '.$connection.' queue connection retry_after above 3600 seconds so a task cannot be reserved again while its worker is running. It is '.$retryAfter.'.');
        }
        if ($target === null || $target->kind !== ExecutionTargetKind::Orb) {
            StartSavedTask::dispatch($task->id, $retry);

            return $task;
        }

        $this->bus->refuseUnclaimable($task, $retry, $task->id.':'.($retry ? 'retry' : 'start'));
        $orbId = $this->targets->handle($target, $task)->targetId;
        try {
            StartSavedTask::dispatch($task->id, $retry, $orbId)->onQueue(Orb::queueName($orbId));
        } catch (Throwable $exception) {
            Orb::releaseTask($task->id);
            throw $exception;
        }

        return $task;
    }
}

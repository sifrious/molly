<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
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
        return $this->request($id, $retry, $target)['task'];
    }

    /**
     * Queue a start or retry and say whether this call added a job. StartSavedTask is unique for
     * each task and kind, so while a start or retry of the task is still queued or running, the
     * queue gets no second job and `queued` is false.
     *
     * @return array{task: Task, queued: bool}
     */
    public function request(string $id, bool $retry = false, ?ExecutionTargetRequest $target = null): array
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
            return ['task' => $task, 'queued' => $this->dispatch(new StartSavedTask($task->id, $retry))];
        }

        $this->bus->refuseUnclaimable($task, $retry, $task->id.':'.($retry ? 'retry' : 'start'));
        $placed = Orb::where('current_task_id', $task->id)->exists();
        $orbId = $this->targets->handle($target, $task)->targetId;
        try {
            $queued = $this->dispatch((new StartSavedTask($task->id, $retry, $orbId))->onQueue(Orb::queueName($orbId)));
        } catch (Throwable $exception) {
            Orb::releaseTask($task->id);
            throw $exception;
        }
        if (! $queued && ! $placed) {
            // The job already queued for this task does not run on the Orb this call reserved.
            Orb::releaseTask($task->id);
        }

        return ['task' => $task, 'queued' => $queued];
    }

    /**
     * Dispatch the job the way PendingDispatch does for a unique job, and return false when the
     * job's unique lock is held, which means Laravel would skip it without saying so.
     */
    private function dispatch(StartSavedTask $job): bool
    {
        $lock = new UniqueLock(app(Cache::class));
        if (! $lock->acquire($job)) {
            return false;
        }
        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $exception) {
            $lock->release($job);
            throw $exception;
        }

        return true;
    }
}

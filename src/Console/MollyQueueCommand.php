<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\QueueTask;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Throwable;

use function Laravel\Prompts\note;

class MollyQueueCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:queue
        {task : Saved task name or ID}
        {--retry : Queue another attempt of a failed or stopped task}
        {--orb= : Place the task on this registered Orb, by name or ID}
        {--orb-runtime= : Place the task on an idle Orb with this runtime, ollama or amp}
        {--orb-model= : Place the task on an idle Orb with this model}
        {--json : Print JSON only}';

    protected $description = 'Queue a start or retry for a queue worker, on this machine or on an Orb';

    public function handle(QueueTask $queue, LocalOrbProvider $orbs): int
    {
        try {
            $target = ExecutionTargetRequest::orb($this->option('orb'), $this->option('orb-runtime'), $this->option('orb-model'));
            ['task' => $task, 'queued' => $queued] = $queue->request((string) $this->argument('task'), (bool) $this->option('retry'), $target);
            $orb = $target === null ? null : $orbs->placementOf($task->id);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'task' => (string) $this->argument('task'), 'error' => $exception->getMessage()]);
        }

        $connection = (string) config('queue.default');
        $queueName = $orb?->queue() ?? (string) (config('queue.connections.'.$connection.'.queue') ?? 'default');
        if ($this->option('json')) {
            $this->writeJson([
                'status' => $queued ? 'queued' : 'already_queued',
                'task_id' => $task->id,
                'task' => $task->reference(),
                'retry' => (bool) $this->option('retry'),
                'connection' => $connection,
                'queue' => $queueName,
                'orb' => $orb === null ? null : ['id' => $orb->id, 'name' => $orb->name, 'runtime' => $orb->runtime, 'model' => $orb->model],
            ]);

            return self::SUCCESS;
        }

        if (! $queued) {
            note('Task '.$task->reference().' already has a '.($this->option('retry') ? 'retry' : 'start').' queued or running on '.($orb === null ? $connection.' / '.$queueName : 'Orb '.$orb->name).'. Molly added no second job.');
            note('Read the result with php artisan molly:task '.$task->reference().'.');

            return self::SUCCESS;
        }
        note($orb === null
            ? 'Queued task '.$task->reference().' on '.$connection.' / '.$queueName.'. php artisan molly:worker start runs it.'
            : 'Queued task '.$task->reference().' on Orb '.$orb->name.' ('.$orb->runtime.($orb->model === null ? '' : ' / '.$orb->model).'). php artisan molly:worker start --orb='.$orb->name.' runs it.');
        note('Queued means requested. Read the result with php artisan molly:task '.$task->reference().'.');

        return self::SUCCESS;
    }
}

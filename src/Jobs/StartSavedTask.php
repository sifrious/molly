<?php

namespace Sifrious\Molly\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;

class StartSavedTask implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    /** The Orb this start runs on, or null for the machine that runs the queue worker. */
    public ?string $orbId = null;

    public function __construct(public string $taskId, public bool $retry = false, ?string $orbId = null)
    {
        $this->orbId = $orbId;
    }

    public function uniqueId(): string
    {
        return $this->taskId.':'.($this->retry ? 'retry' : 'start');
    }

    public function handle(StartTask $start, RetryTask $retry): void
    {
        if ($this->orbId === null) {
            $this->retry ? $retry->handle($this->taskId) : $start->handle($this->taskId);

            return;
        }

        $target = new ExecutionTargetRequest(ExecutionTargetKind::Orb, $this->orbId, 'The start was queued for this Orb.');
        $this->retry ? $retry->handle($this->taskId, target: $target) : $start->handle($this->taskId, target: $target);
    }
}

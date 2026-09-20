<?php

namespace Sifrious\Molly\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\StartTask;

class StartSavedTask implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;


    public function __construct(public string $taskId, public bool $retry = false) {}

    public function uniqueId(): string
    {
        return $this->taskId.':'.($this->retry ? 'retry' : 'start');
    }

    public function handle(StartTask $start, RetryTask $retry): void
    {
        if ($this->retry) {
            $retry->handle($this->taskId);
        } else {
            $start->handle($this->taskId);
        }
    }
}

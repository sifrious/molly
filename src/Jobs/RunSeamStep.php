<?php

namespace Sifrious\Molly\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Sifrious\Molly\Actions\ExecuteSeamStep;

class RunSeamStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $runId) {}

    public function handle(ExecuteSeamStep $execute): void
    {
        $execute->handle($this->runId);
    }
}

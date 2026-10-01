<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Models\Run;

class RetryTask
{
    public function __construct(private StartTask $start) {}

    public function handle(string $id, ?Closure $progress = null, ?ExecutionTargetRequest $target = null): Run
    {
        return $this->start->handle($id, $progress, retry: true, target: $target);
    }

    /** Stop the running attempt because the process received $signal. See StartTask::interrupt(). */
    public function interrupt(int $signal): void
    {
        $this->start->interrupt($signal);
    }
}

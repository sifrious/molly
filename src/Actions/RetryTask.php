<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Sifrious\Molly\Models\Run;

class RetryTask
{
    public function __construct(private StartTask $start) {}

    public function handle(string $id, ?Closure $progress = null): Run
    {
        return $this->start->handle($id, $progress, retry: true);
    }

    /** Stop the running attempt because the process received $signal. See StartTask::interrupt(). */
    public function interrupt(int $signal): void
    {
        $this->start->interrupt($signal);
    }
}

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
}

<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Run;

class ShowRun
{
    public function handle(string $id): ?Run
    {
        return Run::find($id);
    }
}

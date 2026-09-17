<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Sifrious\Molly\Actions\ShowRun;

class RunController
{
    public function show(string $run, ShowRun $show): View
    {
        $record = $show->handle($run);
        abort_if($record === null, 404);

        return view('molly::run', ['run' => $record, 'report' => $record->report ?? []]);
    }
}

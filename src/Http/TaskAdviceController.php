<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Sifrious\Molly\Models\Task;

class TaskAdviceController
{
    public function show(string $task, RecommendTaskNextStep $recommend): View
    {
        $record = Task::findByReference($task);
        abort_if($record === null, 404);

        return view('molly::advice', ['task' => $record, 'advice' => $recommend->handle($record->id)]);
    }
}

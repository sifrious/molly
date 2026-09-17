<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sifrious\Molly\Actions\FindTaskConnections;
use Sifrious\Molly\Actions\LinkTaskThread;
use Sifrious\Molly\Models\Task;

class TaskConnectionController
{
    public function show(string $task, FindTaskConnections $find): View
    {
        $record = Task::findByReference($task);
        abort_if($record === null, 404);

        return view('molly::connections', ['task' => $record, 'connections' => $find->handle($record->id, false)]);
    }

    public function refresh(string $task, FindTaskConnections $find): View
    {
        $record = Task::findByReference($task);
        abort_if($record === null, 404);

        return view('molly::connections', ['task' => $record, 'connections' => $find->handle($record->id)]);
    }

    public function link(Request $request, string $task, LinkTaskThread $link): RedirectResponse
    {
        $record = Task::findByReference($task);
        abort_if($record === null, 404);
        $data = $request->validate(['thread' => ['required', 'string', 'max:38']]);
        try {
            $link->handle($record->id, $data['thread']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['thread' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.connections', $record->id)->with('status', 'Thread linked. No work has started.');
    }
}

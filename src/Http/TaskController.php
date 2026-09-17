<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\QueueTask;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Actions\StopTask;

class TaskController
{
    public function index(ListTasks $tasks): View
    {
        return view('molly::tasks', ['tasks' => $tasks->handle(100)]);
    }

    public function create(): View
    {
        return view('molly::create');
    }

    public function store(Request $request, CreateTask $create, ImportGitHubIssue $import): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['nullable', 'required_without:issue_url', 'string', 'max:8192'],
            'issue_url' => ['nullable', 'string', 'max:2048'],
            'nickname' => ['nullable', 'string'],
            'workspace' => ['required', 'string', 'max:4096'],
            'paths' => ['nullable', 'string', 'max:32768'],
            'test_path' => ['required', 'string', 'max:4096'],
        ]);
        $paths = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['paths'] ?? ''))));
        try {
            $task = ! empty($data['issue_url'])
                ? $import->handle($data['issue_url'], $data['workspace'], $paths, $data['test_path'], nickname: $data['nickname'] ?? null)
                : $create->handle($data['prompt'], $data['workspace'], $paths, $data['test_path'], nickname: $data['nickname'] ?? null);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $task->id)->with('status', 'Task saved. No run has started.');
    }

    public function show(string $task, ShowTask $show): View
    {
        $record = $show->handle($task);
        abort_if($record === null, 404);

        return view('molly::task', ['task' => $record]);
    }

    public function start(string $task, ShowTask $show, QueueTask $queue): RedirectResponse
    {
        return $this->dispatch($task, $show, $queue, false);
    }

    public function name(Request $request, string $task, ShowTask $show, NameTask $name): RedirectResponse
    {
        $record = $show->handle($task);
        abort_if($record === null, 404);
        $data = $request->validate(['nickname' => ['required', 'string']]);

        try {
            $name->handle($record->id, $data['nickname']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['nickname' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $record->id)->with('status', 'Nickname saved. Use the nickname in Molly task commands.');
    }

    public function retry(string $task, ShowTask $show, QueueTask $queue): RedirectResponse
    {
        return $this->dispatch($task, $show, $queue, true);
    }

    private function dispatch(string $task, ShowTask $show, QueueTask $queue, bool $retry): RedirectResponse
    {
        $record = $show->handle($task);
        abort_if($record === null, 404);
        try {
            $queue->handle($record->id, $retry);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['queue' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $record->id)->with('status', 'Execution requested. The queue worker checks task state and attempt limits before starting. Refresh this page to read the result.');
    }

    public function stop(string $task, StopTask $stop): RedirectResponse
    {
        try {
            $record = $stop->handle($task);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $record->id)->with('status', 'Stop requested. An active model request or test process may finish before execution stops.');
    }
}

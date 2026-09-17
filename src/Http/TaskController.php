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
            'workspace' => ['required', 'string', 'max:4096'],
            'paths' => ['required', 'string', 'max:32768'],
            'test_path' => ['required', 'string', 'max:4096'],
        ]);
        $paths = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['paths']))));
        try {
            $task = ! empty($data['issue_url'])
                ? $import->handle($data['issue_url'], $data['workspace'], $paths, $data['test_path'])
                : $create->handle($data['prompt'], $data['workspace'], $paths, $data['test_path']);
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

    public function retry(string $task, ShowTask $show, QueueTask $queue): RedirectResponse
    {
        return $this->dispatch($task, $show, $queue, true);
    }

    private function dispatch(string $task, ShowTask $show, QueueTask $queue, bool $retry): RedirectResponse
    {
        abort_if($show->handle($task) === null, 404);
        try {
            $queue->handle($task, $retry);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['queue' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $task)->with('status', 'Execution requested. The queue worker checks task state and attempt limits before starting. Refresh this page to read the result.');
    }

    public function stop(string $task, StopTask $stop): RedirectResponse
    {
        try {
            $stop->handle($task);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $task)->with('status', 'Stop requested. An active model request or test process may finish before execution stops.');
    }
}

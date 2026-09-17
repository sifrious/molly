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
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Jobs\StartSavedTask;

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

    public function start(string $task, ShowTask $show): RedirectResponse
    {
        return $this->dispatch($task, $show, false);
    }

    public function retry(string $task, ShowTask $show): RedirectResponse
    {
        return $this->dispatch($task, $show, true);
    }

    private function dispatch(string $task, ShowTask $show, bool $retry): RedirectResponse
    {
        abort_if($show->handle($task) === null, 404);
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw ValidationException::withMessages(['queue' => 'Choose a database, Redis, SQS, or Beanstalkd queue connection and start a queue worker before running tasks.']);
        }
        if (in_array($driver, ['database', 'redis', 'beanstalkd'], true) && (int) config('queue.connections.'.config('queue.default').'.retry_after', 0) <= 3600) {
            throw ValidationException::withMessages(['queue' => 'Set the queue connection retry_after above 3600 seconds so a task cannot be reserved again while its worker is running.']);
        }
        StartSavedTask::dispatch($task, $retry);

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

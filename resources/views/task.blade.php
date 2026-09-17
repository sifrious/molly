@extends('molly::layout')
@section('title', 'Task '.$task->id)
@section('content')
<h1>Task</h1>
<dl><dt>Task ID</dt><dd>{{ $task->id }}</dd><dt>Status</dt><dd>{{ $task->status }}</dd><dt>Workspace</dt><dd>{{ $task->workspace }}</dd><dt>Required test</dt><dd>{{ $task->test_path }}</dd></dl>
@if($task->stop_requested_at)<p>Stop requested at {{ $task->stop_requested_at }}.</p>@endif
<h2>Prompt</h2><pre>{{ $task->prompt }}</pre>
<h2>Selected files</h2><ul>@foreach($task->paths as $path)<li><code>{{ $path }}</code></li>@endforeach</ul>
@if($task->source)<h2>Source context</h2><p>Imported issue text is task context, not verification evidence.</p><pre>{{ json_encode($task->source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>@endif
<div class="actions">
@if($task->status === 'pending')<form method="post" action="{{ route('molly.tasks.start', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Start task</flux:button></form>@endif
@if(in_array($task->status, ['failed', 'stopped']))<form method="post" action="{{ route('molly.tasks.retry', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Retry task</flux:button></form>@endif
@if(in_array($task->status, ['pending', 'running']))<form method="post" action="{{ route('molly.tasks.stop', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Stop task</flux:button></form>@endif
<a href="{{ route('molly.tasks.show', $task->id) }}">Refresh task</a>
</div>
<p>The queue worker starts each attempt. Refresh this page to see the result.</p>
<details><summary>If no attempt appears</summary><p>Check the queue worker output and failed jobs. The worker checks the task state and attempt limit before starting a run.</p></details>
<h2>Attempts</h2>
@if($task->runs->isEmpty())<p>No attempts recorded.</p>@else
<table><caption>Run history, oldest first</caption><thead><tr><th scope="col">Run</th><th scope="col">Status</th></tr></thead><tbody>@foreach($task->runs as $run)<tr><th scope="row"><a href="{{ route('molly.runs.show', $run->id) }}">{{ $run->id }}</a></th><td>{{ $run->status }}</td></tr>@endforeach</tbody></table>
@endif
@endsection

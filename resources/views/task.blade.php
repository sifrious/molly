@extends('molly::layout')
@section('title', 'Task '.$task->reference())
@section('content')
<h1>{{ $task->nickname ?? 'Task' }}</h1>
<p><a href="{{ route('molly.tasks.connections', $task->id) }}">Find linked Amp threads</a></p>
<dl><dt>Task ID</dt><dd>{{ $task->id }}</dd><dt>Status</dt><dd>{{ $task->status }}</dd><dt>Workspace</dt><dd>{{ $task->workspace }}</dd><dt>Required test</dt><dd>{{ $task->test_path }}</dd></dl>
<form method="post" action="{{ route('molly.tasks.name', $task->id) }}">
@csrf
<label for="nickname">Nickname</label><input id="nickname" name="nickname" required maxlength="64" value="{{ old('nickname', $task->nickname) }}" aria-describedby="nickname-help">
<p class="hint" id="nickname-help">Choose a unique name such as hello-endpoint to use in task commands. Use letters, digits, or hyphens, starting with a letter. Molly stores nicknames in lowercase.</p>
<div class="actions"><flux:button type="submit" :loading="false">Save nickname</flux:button></div>
</form>
@if($task->stop_requested_at)<p>Stop requested at {{ $task->stop_requested_at }}.</p>@endif
<h2>Prompt</h2><pre>{{ $task->prompt }}</pre>
<h2>Selected files</h2><ul>@foreach($task->paths as $path)<li><code>{{ $path }}</code></li>@endforeach</ul>
@if($task->source)<h2>Source context</h2><p>Imported issue text is task context, not verification evidence.</p><pre>{{ json_encode($task->source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>@endif
<h2>Project journal</h2>
@php($journal = $task->journal_status ?? [])
@if(($journal['status'] ?? null) === 'written')
<p>Project journal refreshed at {{ $journal['checked_at'] }}.</p>
<dl><dt>Journal</dt><dd><code>{{ $journal['journal_path'] }}</code></dd><dt>Glossary</dt><dd><code>{{ $journal['glossary_path'] }}</code></dd></dl>
@elseif(($journal['status'] ?? null) === 'unavailable')
<p><strong>Project journal unavailable.</strong> {{ $journal['reason'] }}</p>
<p>Last checked {{ $journal['checked_at'] }}. The journal warning does not change the task result.</p>
@else<p>Project journal has not been refreshed for this task.</p>@endif
<p>Refresh the project journal from the saved task records:</p>
<pre>php artisan molly:journal {{ $task->reference() }} --project</pre>
<div class="actions">
@if($task->status === 'pending')<form method="post" action="{{ route('molly.tasks.start', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Start task</flux:button></form>@endif
@if(in_array($task->status, ['failed', 'stopped']))<form method="post" action="{{ route('molly.tasks.retry', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Retry task</flux:button></form>@endif
@if(in_array($task->status, ['pending', 'running']))<form method="post" action="{{ route('molly.tasks.stop', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Stop task</flux:button></form>@endif
<a href="{{ route('molly.tasks.show', $task->id) }}">Refresh task</a>
</div>
<p>The queue worker starts each attempt. Refresh this page to see the result.</p>
<details><summary>If no attempt appears</summary><p>Check the queue worker output and failed jobs. The worker checks the task state and attempt limit before starting a run.</p></details>
<form method="post" action="{{ route('molly.tasks.advice', $task->id) }}">
@csrf
<flux:button type="submit" :loading="false">Get next-step advice</flux:button>
<p>Check attempt limits and saved evidence. Optional TypeSafe evaluation may recommend a next step.</p>
</form>
<h2>Attempts</h2>
@if($task->runs->isEmpty())<p>No attempts recorded.</p>@else
<table><caption>Run history, oldest first</caption><thead><tr><th scope="col">Run</th><th scope="col">Status</th></tr></thead><tbody>@foreach($task->runs as $run)<tr><th scope="row"><a href="{{ route('molly.runs.show', $run->id) }}">{{ $run->id }}</a></th><td>{{ $run->status }}</td></tr>@endforeach</tbody></table>
@endif
@endsection

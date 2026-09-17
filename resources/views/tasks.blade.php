@extends('molly::layout')
@section('title', 'Tasks')
@section('content')
<h1>Tasks</h1>
<p>Save a prompt and selected files, then start a run. The latest 100 tasks appear here.</p>
@if($tasks->isEmpty())<p>No tasks yet. Create a task to get started.</p>@else
<div class="table-scroll"><table><caption>Saved tasks</caption><thead><tr><th scope="col">Task</th><th scope="col">Status</th><th scope="col">Created</th></tr></thead><tbody>
@foreach($tasks as $task)<tr><th scope="row"><a href="{{ route('molly.tasks.show', $task->id) }}">{{ $task->nickname ?? \Illuminate\Support\Str::limit($task->prompt, 100) }}</a>@if($task->nickname)<div>{{ \Illuminate\Support\Str::limit($task->prompt, 100) }}</div>@endif<div class="hint"><code>{{ $task->id }}</code></div></th><td>{{ $task->status }}</td><td>{{ $task->created_at }}</td></tr>@endforeach
</tbody></table></div>
@endif
@endsection

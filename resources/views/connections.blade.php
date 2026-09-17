@extends('molly::layout')
@section('title', 'Connections for '.$task->reference())
@section('content')
<p><a href="{{ route('molly.tasks.show', $task->id) }}">Back to {{ $task->reference() }}</a></p>
<h1>Connections for {{ $task->reference() }}</h1>
<p>Link an Amp thread to keep a record of where you worked on this task. Moving the thread to another task keeps the earlier association.</p>
<form method="post" action="{{ route('molly.tasks.connections.link', $task->id) }}">
@csrf
<label for="thread">Amp thread ID</label>
<input id="thread" name="thread" required maxlength="38" value="{{ old('thread') }}" placeholder="T-00000000-0000-0000-0000-000000000000" aria-describedby="thread-help">
<p id="thread-help" class="hint">Copy the ID from an Amp thread URL. Saving a link does not start work or verify a connection.</p>
<div class="actions"><flux:button type="submit" :loading="false">Link thread</flux:button></div>
</form>
<h2>Task association history</h2>
<p>{{ $connections['reason'] ?? 'Amp connection status observed.' }}</p>
@if($connections['observed_at'])<p>Checked at {{ $connections['observed_at'] }} using Amp {{ $connections['provider_version'] ?? 'with an unknown version' }}.</p>@endif
@if($connections['matches'] === [])
<p>No linked threads yet.</p>
@else
<form method="post" action="{{ route('molly.tasks.connections.refresh', $task->id) }}">@csrf<flux:button type="submit" :loading="false">Check connections</flux:button></form>
<table>
<caption>Amp threads linked to this task</caption>
<thead><tr><th scope="col">Thread</th><th scope="col">Connection</th><th scope="col">Task association</th><th scope="col">Linked at</th></tr></thead>
<tbody>
@foreach($connections['matches'] as $match)
<tr>
<th scope="row"><a href="{{ $match['url'] }}">{{ $match['thread_id'] }}</a></th>
<td>{{ ucfirst($match['connection']) }}@if($match['working'] !== null)<br>{{ $match['working'] ? 'Working' : 'Not working' }}@endif</td>
<td>@if($match['association'] === 'latest_recorded')Latest recorded task @else Prior task. Latest link is <a href="{{ route('molly.tasks.show', $match['latest_task']['id']) }}">{{ $match['latest_task']['reference'] }}</a>. @endif</td>
<td>{{ $match['linked_at'] }}</td>
</tr>
@endforeach
</tbody></table>
@endif
@if($connections['truncated'])<p>Showing the 20 most recently linked threads.</p>@endif
<p>Task links come from your saved associations. Amp reports whether an executor is connected and working. Those facts do not verify an Orb identity, prove the executor is working on this Molly task, or permit Molly to send work.</p>
@endsection

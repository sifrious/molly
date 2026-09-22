@extends('molly::layout')
@section('title', 'Next step for '.$task->reference())
@section('content')
<p><a href="{{ route('molly.tasks.show', $task->id) }}">Back to {{ $task->reference() }}</a></p>
<h1>Next step for {{ $task->reference() }}</h1>
<p>{{ $advice['reason'] }}</p>
<dl>
<dt>Suggested next step</dt><dd>{{ ucfirst($advice['next_action']) }}</dd>
<dt>Task status when checked</dt><dd>{{ $advice['observed']['task_status'] }}</dd>
<dt>Attempts used</dt><dd>{{ $advice['observed']['attempt_count'] }} of {{ $advice['observed']['max_attempts'] ?? 'an invalid limit' }}</dd>
<dt>Retry permitted</dt><dd>{{ $advice['retry_allowed'] ? 'Yes' : 'No' }}</dd>
<dt>Checked at</dt><dd>{{ $advice['recorded_at'] }}</dd>
<dt>Advice saved with the run</dt><dd>{{ $advice['persisted'] ? 'Yes' : 'No' }}</dd>
</dl>
@if($advice['command'])<p>When you are ready, run:</p><pre>{{ $advice['command'] }}</pre>@endif
<h2>Semantic evaluation</h2>
@php
    $providerName = e($advice['provider']['name'] ?? 'unset');
    $providerStatus = e(str_replace('_', ' ', $advice['provider']['status'] ?? 'unavailable'));
    $providerReason = e(str_replace('_', ' ', $advice['provider']['reason'] ?? 'unavailable'));
@endphp
@if(($advice['status'] ?? '') === 'evaluated')
    <p>{{ $providerName }} returned a choice from the allowed options.</p>
@elseif(($advice['provider']['status'] ?? '') === 'disabled' || ($advice['provider']['reason'] ?? '') === 'disabled')
    <p>Jev classification is disabled. Molly used saved task state and checks only.</p>
@elseif(in_array(($advice['provider']['reason'] ?? ''), ['invalid_config', 'provider_unavailable'], true) || ($advice['provider']['status'] ?? '') === 'unavailable')
    <p>No usable provider recommendation was available ({{ $providerReason }}). Molly used saved task state and checks only.</p>
@elseif(($advice['provider']['reason'] ?? '') === 'low_confidence')
    <p>Provider confidence was below the configured threshold. Molly kept deterministic guidance from saved task state and checks.</p>
@else
    <p>Molly used saved task state and checks. No usable model recommendation was applied.</p>
@endif
<dl>
    <dt>Provider</dt><dd>{{ $providerName }}</dd>
    <dt>Provider status</dt><dd>{{ ucfirst($providerStatus) }}</dd>
    <dt>Provider reason</dt><dd>{{ ucfirst($providerReason) }}</dd>
</dl>
@if($advice['provider']['model'])<p>Model: {{ e($advice['provider']['model']) }}</p>@endif
@if($advice['confidence'] !== null)<p>Provider confidence: {{ e((string) $advice['confidence']) }}. This describes the returned choice distribution, not the probability that the advice is correct.</p>@endif
<p>Advice does not start or retry work. The task must still pass Pest, Tarpit review, and the required measurement checks.</p>
@if($advice['run_id'])<p><a href="{{ route('molly.runs.show', $advice['run_id']) }}">Read the run evidence</a></p>@endif
<details><summary>Recorded advice</summary><pre>{{ json_encode($advice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre></details>
@endsection

@extends('molly::layout')
@section('title', 'Run evidence')
@section('content')
@php
    $checks = $report['review']['checks'] ?? [];
    $findings = $report['review']['findings'] ?? [];
    $blocking = count(array_filter($findings, fn ($finding) => ($finding['severity'] ?? null) === 'blocking'));
@endphp
<h1>Run evidence</h1>
<livewire:molly-run-status :run-id="$run->id" />
<div class="actions">
    <a href="{{ route('molly.runs.show', $run->id) }}">Refresh all evidence</a>
    @if($run->task_id)<a href="{{ route('molly.tasks.show', $run->task_id) }}">View task and attempts</a>@endif
</div>
@if(isset($report['summary']))<p>{{ $report['summary'] }}</p>@endif
@if(isset($report['error']))<p class="notice errors">{{ is_string($report['error']) ? $report['error'] : json_encode($report['error']) }}</p>@endif
<div class="evidence-summary">
    <p>Tarpit: {{ count($checks) }} of 7 checks recorded. {{ count($findings) }} {{ count($findings) === 1 ? 'finding' : 'findings' }} recorded, {{ $blocking }} blocking.</p>
    <p>Clever: before {{ $report['complexity_before']['status'] ?? 'Not run' }}; after {{ $report['complexity_after']['status'] ?? 'Not run' }}.</p>
    <p>Pest: {{ $report['verification']['status'] ?? 'Not run' }}. Tests: {{ $report['verification']['tests'] ?? 'Not recorded' }}. Assertions: {{ $report['verification']['assertions'] ?? 'Not recorded' }}.</p>
</div>
<nav class="report-nav" aria-label="Run evidence sections">
    <a href="#tarpit">Tarpit findings</a>
    <a href="#clever">Clever measurements</a>
    <a href="#pest">Pest verification</a>
    <a href="#changes">What changed</a>
</nav>
<h2 id="tarpit">Tarpit review</h2>
<p>The model reviews supplied files. A clean review does not prove whole-repository safety.</p>
<table><caption>All seven complexity checks</caption><thead><tr><th scope="col">Check</th><th scope="col">Status</th><th scope="col">Evidence</th></tr></thead><tbody>
@foreach(['A' => 'Derived state', 'B' => 'I/O and domain decisions', 'C' => 'Transport policy', 'D' => 'Hidden ordering', 'E' => 'Unused abstractions', 'F' => 'Size, nesting, duplication', 'G' => 'Caches and dependencies'] as $code => $label)
<tr><th scope="row">{{ $code }}. {{ $label }}</th><td>{{ $report['review']['checks'][$code]['status'] ?? 'Not run' }}</td><td>{{ $report['review']['checks'][$code]['evidence'] ?? 'No evidence recorded.' }}</td></tr>
@endforeach
</tbody></table>
<h3>Findings</h3>
@forelse($report['review']['findings'] ?? [] as $finding)
<article><h4>{{ $finding['path'] }}:{{ $finding['line'] }}</h4><p>{{ $finding['severity'] }} / {{ $finding['classification'] }}</p><p>{{ $finding['problem'] }}</p><p>Suggested change: {{ $finding['recommendation'] }}</p></article>
@empty<p>No findings recorded. Missing review evidence does not count as a clean review.</p>@endforelse
<h2 id="clever">Clever measurements</h2>
<p>Read each measurement separately. Lower counts alone do not prove a simpler design. Skipped checks are not passing checks. The default owned paths omit tests and resources/views.</p>
@php
    $beforeProbes = collect($report['complexity_before']['probes'] ?? [])->keyBy('key');
    $afterProbes = collect($report['complexity_after']['probes'] ?? [])->keyBy('key');
    $probeKeys = $beforeProbes->keys()->merge($afterProbes->keys())->unique();
@endphp
@foreach(['complexity_before' => 'Before changes', 'complexity_after' => 'After changes'] as $key => $label)
    @if(isset($report[$key]['reason']))<p>{{ $label }}: {{ $report[$key]['reason'] }}</p>@endif
@endforeach
@if($probeKeys->isEmpty())<p>No Clever measurements recorded.</p>@endif
@foreach($probeKeys as $key)
    @include('molly::probe', ['before' => $beforeProbes->get($key, []), 'after' => $afterProbes->get($key, []), 'probeKey' => $key])
@endforeach
<h2 id="pest">Pest verification</h2>
<p>Status: {{ $report['verification']['status'] ?? 'Not run' }}. Tests: {{ $report['verification']['tests'] ?? 'Not recorded' }}. Assertions: {{ $report['verification']['assertions'] ?? 'Not recorded' }}.</p>
<p>Pest runs only the selected test file. Review the assertions because Molly may edit that file.</p>
@if(isset($report['verification']['output']))<details><summary>Test output</summary><pre>{{ $report['verification']['output'] }}</pre></details>@endif
<h2 id="changes">Changed files</h2>
@if($report['changes_unavailable'] ?? false)<p>Changed files could not be read. Inspect the workspace before retrying.</p>@endif
@forelse($report['changes'] ?? [] as $change)
<details>
    <summary>{{ $change['path'] }} · {{ $change['status'] ?? 'Status not recorded' }}</summary>
    <dl>
        <dt>Before SHA-256</dt><dd><code>{{ $change['before_hash'] ?? 'Not recorded or file absent' }}</code></dd>
        <dt>After SHA-256</dt><dd><code>{{ $change['after_hash'] ?? 'Not recorded or file absent' }}</code></dd>
    </dl>
    <p>File contents are not stored in this report. Compare the workspace diff with these recorded hashes.</p>
</details>
@empty<p>No changed files recorded.</p>@endforelse
<h3>Component changes</h3>
@if(($report['components']['status'] ?? null) === 'compared')
    @if(empty($report['components']['changes']))<p>No recognized component source files were present in either run snapshot.</p>@else
    <div class="table-scroll"><table><caption>Selected component source changes</caption><thead><tr><th scope="col">Component ID</th><th scope="col">Kind</th><th scope="col">Status</th></tr></thead><tbody>
    @foreach($report['components']['changes'] as $component)<tr><th scope="row"><code>{{ $component['id'] }}</code></th><td>{{ $component['kind'] }}</td><td>{{ $component['status'] }}</td></tr>@endforeach
    </tbody></table></div>
    @endif
@else<p>{{ $report['components']['reason'] ?? 'Component changes were not recorded.' }}</p>@endif
<p>Component IDs describe recognized Blade and Livewire source paths. A source change does not establish a visual change.</p>
<h3>Source snapshots</h3>
@if(empty($report['snapshots']))<p>Component snapshots were not recorded for this run.</p>@else
    @foreach(['task_creation' => 'Task creation', 'before' => 'Before execution', 'after' => 'After execution'] as $key => $label)
    @php($snapshot = $report['snapshots'][$key] ?? [])
    <details><summary>{{ $label }}: {{ $snapshot['status'] ?? 'Not recorded' }}</summary>
        @if(($snapshot['status'] ?? null) === 'captured')
        <p>Captured {{ $snapshot['captured_at'] }}. A null hash means the selected file was absent at capture.</p>
        <div class="table-scroll"><table><caption>{{ $label }} source metadata</caption><thead><tr><th scope="col">File</th><th scope="col">SHA-256</th><th scope="col">Component ID</th></tr></thead><tbody>
        @foreach($snapshot['files'] as $file)<tr><th scope="row"><code>{{ $file['path'] }}</code></th><td><code>{{ $file['sha256'] ?? 'File absent' }}</code></td><td><code>{{ $file['component']['id'] ?? 'Not a recognized component path' }}</code></td></tr>@endforeach
        </tbody></table></div>
        <p>Preview: {{ $snapshot['preview']['status'] }}. {{ $snapshot['preview']['reason'] }}</p>
        @elseif($key === 'task_creation' && $snapshot === [])<p>No task-creation snapshot is available. The original task context is unknown.</p>
        @else<p>{{ $snapshot['reason'] ?? 'Snapshot evidence is unavailable.' }}</p>@endif
    </details>
    @endforeach
@endif
@if(isset($report['advice']))
<section aria-labelledby="advice-heading">
<h2 id="advice-heading">Saved next-step advice</h2>
<p>{{ $report['advice']['reason'] ?? 'Read the saved advice in the complete report.' }}</p>
<p>Requested at {{ $report['advice']['recorded_at'] ?? 'an unknown time' }}. Task commands check current state and limits again.</p>
</section>
@endif
<h2>Run details</h2>
<details><summary>Run ID and workspace</summary><dl><dt>Run ID</dt><dd>{{ $run->id }}</dd><dt>Workspace</dt><dd>{{ $run->workspace }}</dd></dl></details>
@if(!empty($report['branches']))
<details><summary>Execution branch details</summary>
<p>Execution mode: {{ $report['mode'] ?? 'Not recorded' }}. A branch result is separate from the run's completion decision.</p>
<div class="table-scroll"><table><caption>Recorded branch outcomes</caption><thead><tr><th scope="col">Branch</th><th scope="col">Status</th><th scope="col">Execution</th><th scope="col">Timing</th><th scope="col">Failure</th></tr></thead><tbody>
@foreach($report['branches'] as $branch)
<tr><th scope="row">{{ $branch['kind'] ?? 'Unknown' }}<br><code>{{ $branch['branch_id'] ?? 'Not recorded' }}</code></th><td>{{ $branch['status'] ?? 'Not recorded' }}</td><td>{{ $branch['execution_target'] ?? 'Not recorded' }}<br>{{ $branch['provider'] ?? '' }} {{ $branch['model'] ?? '' }}</td><td>Started {{ $branch['started_at'] ?? 'Not recorded' }}<br>Finished {{ $branch['finished_at'] ?? 'Not recorded' }}</td><td>{{ $branch['failure_classification'] ?? 'None recorded' }}</td></tr>
@endforeach
</tbody></table></div></details>
@endif
<details><summary>Full saved report as JSON</summary><pre>{{ json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre></details>
@endsection

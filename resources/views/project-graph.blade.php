@extends('molly::layout')
@section('title', 'Project graph')
@section('content')
<h1>Project graph</h1>
<p>Local relationships among saved tasks, acceptance tests, attempts, and verification blockers. This page rebuilds the graph from saved records. It does not start an agent or open a pull request.</p>
<form method="get" action="{{ route('molly.graph') }}">
    <label for="workspace">Workspace</label>
    <input id="workspace" name="workspace" value="{{ $workspace }}" required maxlength="4096">
    <p class="hint">Molly indexes saved records for this directory. Choose an existing project path.</p>
    <div class="actions"><button type="submit">Rebuild graph</button></div>
</form>
<dl>
    <dt>Workspace</dt><dd><code>{{ $workspace }}</code></dd>
    <dt>Graph file</dt><dd><code>{{ $database }}</code></dd>
</dl>
@if($truncated)
<p class="notice" role="status">The view reached its 40-node limit. Blockers and tasks are listed first. Narrow the workspace or inspect one task from the CLI with <code>php artisan molly:project:query</code>.</p>
@endif
<h2>Verification blockers</h2>
@if($blockers === [])
<p>No required verifier failures are indexed for this workspace.</p>
@else
<div class="table-scroll"><table>
    <caption>Required checks that blocked completion</caption>
    <thead><tr><th scope="col">Blocker</th><th scope="col">Verifier</th><th scope="col">State</th></tr></thead>
    <tbody>
    @foreach($blockers as $blocker)
        <tr>
            <th scope="row">{{ $blocker['label'] }}</th>
            <td>{{ $blocker['metadata']['verifier'] ?? 'Not recorded' }}</td>
            <td>{{ $blocker['metadata']['state'] ?? 'Not recorded' }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
<h2>Nodes</h2>
<div class="table-scroll"><table>
    <caption>Indexed records, blockers first</caption>
    <thead><tr><th scope="col">Type</th><th scope="col">Name</th></tr></thead>
    <tbody>
    @foreach($nodes as $node)
        <tr>
            <th scope="row">{{ $node['type'] }}</th>
            <td>{{ $node['label'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<h2>Relationships</h2>
@php($labels = collect($nodes)->mapWithKeys(fn ($node) => [$node['id'] => $node['label']]))
<div class="table-scroll"><table>
    <caption>Typed edges in this snapshot</caption>
    <thead><tr><th scope="col">Relationship</th><th scope="col">From</th><th scope="col">To</th></tr></thead>
    <tbody>
    @foreach($edges as $edge)
        <tr>
            <th scope="row">{{ $edge['relation'] }}</th>
            <td>{{ $labels[$edge['from']] ?? $edge['from'] }}</td>
            <td>{{ $labels[$edge['to']] ?? $edge['to'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<p>Tarpit findings and required verifier failures stay evidence. They are not rewritten as vague labels.</p>
@endsection

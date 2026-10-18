@extends('molly::layout')
@section('title', 'Readiness')
@section('content')
<h1>Readiness</h1>
<p>This page runs the same checks as <code>php artisan molly:doctor</code> for one workspace, except the database write probe. Refresh reads the checks again. It does not install a model, start a task, or write a plan row.</p>
<form method="get" action="{{ route('molly.readiness') }}">
    <label for="workspace">Workspace</label>
    <input id="workspace" name="workspace" value="{{ $workspace }}" required maxlength="4096" aria-describedby="workspace-help">
    <p class="hint" id="workspace-help">An existing Laravel project directory. The same path a task stores as its workspace.</p>
    <div class="actions"><button type="submit">Refresh checks</button></div>
</form>
@if($error)
<p class="notice errors" role="alert">{{ $error }}</p>
@elseif($report['ready'])
<p class="notice" role="status">This workspace has no failed checks. A check marked Unknown was not measured, and Unknown is not a pass.</p>
@else
<p class="notice errors" role="alert">This workspace needs action. Read the next step on each failed check. Refresh does not install a model or start a task.</p>
@endif
@if($report)
<div class="table-scroll"><table>
    <caption>Readiness checks for this workspace</caption>
    <thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Code</th><th scope="col">Next step</th></tr></thead>
    <tbody>
    @foreach($report['checks'] as $row)
        <tr>
            <th scope="row">{{ $row['name'] }}</th>
            <td>{{ match ($row['status']) { 'passed' => 'Passed', 'failed' => 'Failed', default => 'Unknown' } }}</td>
            <td><code>{{ $row['code'] }}</code></td>
            <td>{{ $row['message'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<p>Passed means the check succeeded. Failed means it did not, and the next step is the text from that check. Unknown means Molly did not measure it. The Run history row stays Unknown here because this page does not insert a row. <code>php artisan molly:doctor --workspace={{ $workspace }}</code> runs that probe and rolls it back.</p>
@endif
@endsection

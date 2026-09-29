@extends('molly::layout')
@section('title', 'Learning paths')
@section('content')
<h1>Learning paths</h1>
<p>Goal-oriented walks over Molly's knowledge and project graphs. Each path follows graph relationships from a starting concept through connected nodes. Gaps are reported when connectivity is missing.</p>

@if($templates === [])
<p class="notice">No path templates are available. Index a knowledge graph first with <code>php artisan molly:knowledge:index laravel</code>.</p>
@else
<div class="table-scroll"><table>
    <caption>Available path templates</caption>
    <thead><tr><th scope="col">Path</th><th scope="col">Namespace</th><th scope="col">Description</th><th scope="col"></th></tr></thead>
    <tbody>
    @foreach($templates as $template)
        <tr>
            <th scope="row">{{ $template['title'] }}</th>
            <td><code>{{ $template['namespace'] }}</code></td>
            <td>{{ $template['description'] }}</td>
            <td><a href="{{ route('molly.learning-paths.show', $template['id']) }}">Walk path</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endif
@endsection

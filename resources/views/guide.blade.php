@extends('molly::layout')
@section('title', 'Planning guide')
@section('content')
<h1>Planning guide</h1>
<p>The bundled guide connects planning questions to source excerpts. The questions help you define small tasks. The guide does not inspect your repository or generate an assessment.</p>
<p>Guide version {{ $graph['version'] }}. <a href="{{ route('molly.plans.create') }}">Start a plan</a>.</p>
<nav aria-label="Planning guide sections"><a href="#questions">Questions</a><a href="#sources">Source excerpts</a><a href="#connections">Connections</a></nav>
<h2 id="questions">Questions</h2>
<ol>
    @foreach($graph['steps'] as $step)
        <li id="step:{{ $step['id'] }}">
            <h3>{{ $step['title'] }}</h3>
            <p>{{ $step['question'] }}</p>
            <p>{{ $step['help'] }}</p>
            <ul>@foreach($step['source_ids'] as $sourceId)<li><a href="#source:{{ $sourceId }}">{{ $titles['source:'.$sourceId] }}</a></li>@endforeach</ul>
        </li>
    @endforeach
</ol>
<h2 id="sources">Source excerpts</h2>
<p>Each excerpt records its source and revision. Check the version used by your project before applying an API example.</p>
@foreach($sources as $source)
    <section id="source:{{ $source['id'] }}" aria-labelledby="source-title-{{ $source['id'] }}">
        <h3 id="source-title-{{ $source['id'] }}">{{ $source['title'] }}</h3>
        <p><a href="{{ $source['url'] }}">Read the source</a></p>
        <dl><dt>Source ID</dt><dd>{{ $source['id'] }}</dd><dt>Revision</dt><dd>{{ $source['revision'] }}</dd></dl>
        <details><summary>Read the bundled excerpt</summary><pre>{{ $source['content'] }}</pre></details>
    </section>
@endforeach
<h2 id="connections">Connections</h2>
<div class="table-scroll"><table>
    <caption>Question order and source references</caption>
    <thead><tr><th scope="col">From</th><th scope="col">Connection</th><th scope="col">To</th></tr></thead>
    <tbody>@foreach($graph['edges'] as $edge)<tr>
        <th scope="row"><a href="#{{ $edge['from'] }}">{{ $titles[$edge['from']] }}</a></th>
        <td>{{ $edge['relation'] === 'next' ? 'Next question' : 'Consult source' }}</td>
        <td><a href="#{{ $edge['to'] }}">{{ $titles[$edge['to']] }}</a></td>
    </tr>@endforeach</tbody>
</table></div>
@endsection

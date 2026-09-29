@extends('molly::layout')
@section('title', $path->title)
@section('content')
<h1>{{ $path->title }}</h1>
<p>{{ $path->description }}</p>

<dl>
    <dt>Template</dt><dd><code>{{ $path->templateId ?? $path->id }}</code></dd>
    <dt>Freshness</dt><dd>{{ $path->freshness->value }}</dd>
    @if($path->repository)<dt>Repository</dt><dd><code>{{ $path->repository }}</code></dd>@endif
    @if($path->revisionRef)<dt>Revision</dt><dd><code>{{ $path->revisionRef }}</code></dd>@endif
</dl>

@if($path->error)
<p class="notice errors" role="alert">{{ $path->error }}</p>
@endif

@if($path->gaps !== [])
<h2>Gaps</h2>
<ul>
    @foreach($path->gaps as $gap)
        <li>{{ $gap }}</li>
    @endforeach
</ul>
@endif

@if($path->steps === [])
<p class="notice">This path has no steps. The seed concept may not exist in the indexed graph.</p>
@else
<h2>Steps</h2>
<ol>
    @foreach($path->steps as $step)
        <li id="step-{{ $step->position }}">
            <strong>{{ $step->node }}</strong> <span>({{ $step->nodeKind }})</span>
            <p>{{ $step->why }}</p>
            @if($step->citations !== [])
            <details>
                <summary>Citations</summary>
                <ul>
                    @foreach($step->citations as $citation)
                        <li>
                            <code>{{ $citation->path }}</code>
                            @if($citation->revisionRef) at <code>{{ $citation->revisionRef }}</code>@endif
                            @if($citation->lineStart) lines {{ $citation->lineStart }}@if($citation->lineEnd)–{{ $citation->lineEnd }}@endif @endif
                        </li>
                    @endforeach
                </ul>
            </details>
            @endif
            @if($step->nextRelationship)
            <p class="hint">Next: <code>{{ $step->nextRelationship }}</code>@if($step->nextNode) → {{ $step->nextNode }}@endif</p>
            @endif
            @if($step->exercise)
            <div class="evidence-summary"><p>{{ $step->exercise }}</p></div>
            @endif
        </li>
    @endforeach
</ol>
@endif

@if($path->complete)
<p class="notice" role="status">Path complete. All steps connected through graph relationships.</p>
@endif

<div class="actions">
    <a href="{{ route('molly.learning-paths.index') }}" data-flux-button>All paths</a>
</div>
@endsection

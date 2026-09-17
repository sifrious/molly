@extends('molly::layout')
@section('title', 'Plan '.$plan->id)
@section('content')
<h1>Project plan</h1>
<p><a href="{{ route('molly.plans.index') }}">Saved plans</a></p>
@if($canSuggest)
    <form method="post" action="{{ route('molly.plans.suggest', $plan->id) }}">
        @csrf
        <p>Ask Jev which Tarpit question deserves attention. This sends the plan, answers, and bundled source excerpts to TypeSafe. It does not change your answers or approve a task.</p>
        <button type="submit">Ask Jev for a Tarpit suggestion</button>
    </form>
@else
    <p>Jev suggestions are not configured. Guided review works offline.</p>
@endif
@if($plan->suggestion)
    <section aria-labelledby="suggestion-heading">
        <h2 id="suggestion-heading">Jev suggestion</h2>
        <p>Status: {{ $plan->suggestion['status'] ?? 'Unavailable' }}</p>
        <p>{{ $plan->suggestion['reason'] ?? '' }}</p>
        @if(($plan->suggestion['answers_at_evaluation'] ?? []) !== ($plan->answers ?? []))
            <p>Your answers have changed since this suggestion.</p>
        @endif
        @if(($plan->suggestion['status'] ?? '') === 'evaluated')
            <p>{{ $plan->suggestion['question'] ?? '' }}</p>
            <p>Confidence: {{ $plan->suggestion['confidence'] ?? 'Not provided' }}</p>
            <ul>
                @foreach($plan->suggestion['sources'] ?? [] as $source)
                    <li><a href="{{ $source['url'] }}">{{ $source['title'] }}</a>, revision {{ $source['revision'] }}</li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
@if($tasks->isNotEmpty())
    <h2>Tasks from this plan</h2>
    <ul>
        @foreach($tasks as $task)
            <li><a href="{{ route('molly.tasks.show', $task->id) }}">{{ $task->id }}</a>, {{ $task->status }}</li>
        @endforeach
    </ul>
@endif
<dl>
    <dt>Plan ID</dt><dd>{{ $plan->id }}</dd>
    <dt>Review</dt><dd>{{ $plan->review_mode === 'skip' ? 'Skipped by choice' : ($plan->completed() ? 'Questions complete' : 'In progress') }}</dd>
    <dt>Guide version</dt><dd>{{ $plan->guide_version }}</dd>
</dl>
<h2>What you want to build</h2>
<pre>{{ $plan->description }}</pre>
@if($plan->answers)
    <h2>Your answers</h2>
    <dl>
        @foreach($steps as $step)
            @if(array_key_exists($step['id'], $plan->answers))
                <dt>{{ $step['title'] }}</dt>
                <dd><pre>{{ $plan->answers[$step['id']] }}</pre></dd>
            @endif
        @endforeach
    </dl>
@endif
@if($nextStep)
    <section aria-labelledby="question-heading">
        <h2 id="question-heading">{{ $nextStep['title'] }}</h2>
        <form method="post" action="{{ route('molly.plans.answers', $plan->id) }}">
            @csrf
            <input type="hidden" name="step" value="{{ $nextStep['id'] }}">
            <label for="answer">{{ $nextStep['question'] }}</label>
            <textarea id="answer" name="answer" required maxlength="600" aria-describedby="answer-help">{{ old('answer') }}</textarea>
            <p class="hint" id="answer-help">{{ $nextStep['help'] }} Limit 600 UTF-8 bytes.</p>
            <div class="actions"><button type="submit">Save answer and continue</button></div>
        </form>
        <p>Each answer is saved. You can return to this plan later.</p>
        <p>Sources for this question:</p>
        <ul>@foreach($nextStep['source_ids'] as $sourceId)<li><a href="{{ route('molly.guide') }}#source:{{ $sourceId }}">{{ $sourceTitles[$sourceId] }}</a></li>@endforeach</ul>
    </section>
@endif
<section aria-labelledby="sources-heading">
    <h2 id="sources-heading">Source references</h2>
    <p><a href="{{ route('molly.guide') }}">Browse all bundled sources and their connections</a></p>
    <p>These bundled excerpts offer questions to consider. Your answers define the plan.</p>
    @forelse($sources as $source)
        <details>
            <summary>{{ $source['title'] }}</summary>
            <p><a href="{{ $source['url'] }}">Read the source</a></p>
            <dl><dt>Source ID</dt><dd>{{ $source['id'] }}</dd><dt>Revision</dt><dd>{{ $source['revision'] }}</dd></dl>
            <pre>{{ $source['content'] }}</pre>
        </details>
    @empty
        <p>No matching source excerpts.</p>
    @endforelse
</section>
@if($plan->completed())
    <section aria-labelledby="task-heading">
        <h2 id="task-heading">Choose the first task</h2>
        <p>Choose one small change from the plan. Saving a task does not start a run.</p>
        <form method="post" action="{{ route('molly.plans.tasks', $plan->id) }}">
            @csrf
            <label for="prompt">What should Molly work on?</label>
            <textarea id="prompt" name="prompt" required maxlength="1000" aria-describedby="prompt-help">{{ old('prompt') }}</textarea>
            <p class="hint" id="prompt-help">Name the behavior and how the required test should verify the result. Limit 1000 UTF-8 bytes.</p>
            <label for="workspace">Workspace directory</label><input id="workspace" name="workspace" required value="{{ old('workspace', base_path()) }}">
            <label for="paths">Files Molly may change</label><textarea id="paths" name="paths" required aria-describedby="paths-help">{{ old('paths') }}</textarea>
            <p class="hint" id="paths-help">One repository-relative path per line. Include the required test file.</p>
            <label for="test_path">Required Pest test file</label><input id="test_path" name="test_path" required value="{{ old('test_path') }}" aria-describedby="test-help">
            <p class="hint" id="test-help">A PHP file under tests/, also listed above.</p>
            <div class="actions"><button type="submit">Save task from plan</button></div>
        </form>
    </section>
@endif
@endsection

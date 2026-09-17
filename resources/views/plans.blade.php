@extends('molly::layout')
@section('title', 'Saved plans')
@section('content')
<h1>Saved plans</h1>
<p><a href="{{ route('molly.plans.create') }}">Plan a project</a></p>
<p>The 20 most recent plans. Open a plan to continue its Tarpit review or choose another task.</p>
@forelse($plans as $plan)
    <article>
        <h2><a href="{{ route('molly.plans.show', $plan->id) }}">{{ $plan->description }}</a></h2>
        <p>{{ $plan->review_mode === 'skip' ? 'Review skipped by choice' : ($plan->completed() ? 'Questions complete' : 'Review in progress') }}</p>
    </article>
@empty
    <p>No saved plans yet.</p>
@endforelse
@endsection

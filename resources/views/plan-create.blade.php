@extends('molly::layout')
@section('title', 'Plan a project')
@section('content')
<h1>Plan a project</h1>
<p><a href="{{ route('molly.plans.index') }}">Saved plans</a></p>
<p>Describe a project or collection of tasks. The optional Tarpit review asks one question at a time before you choose a small task to run.</p>
<p>Planning saves your answers and shows bundled source references. Saving a plan and answering the guided questions do not call a model or change files. You can request a separate Jev suggestion when TypeSafe is configured.</p>
<form method="post" action="{{ route('molly.plans.store') }}">
    @csrf
    <label for="description">What would you like to build?</label>
    <textarea id="description" name="description" required maxlength="1500" aria-describedby="description-help">{{ old('description') }}</textarea>
    <p class="hint" id="description-help">Describe the result you need and who will use the project. Limit 1500 UTF-8 bytes. Some characters use more than one byte.</p>
    <fieldset>
        <legend>Walk through the Tarpit review?</legend>
        <label><input type="radio" name="review_mode" value="guided" @checked(old('review_mode', 'guided') === 'guided')> Yes, work through the questions</label>
        <label><input type="radio" name="review_mode" value="skip" @checked(old('review_mode') === 'skip')> Skip the review and choose a task</label>
    </fieldset>
    <div class="actions"><button type="submit">Save plan</button></div>
</form>
<p><a href="{{ route('molly.guide') }}">Browse the bundled sources and questions</a></p>
@endsection

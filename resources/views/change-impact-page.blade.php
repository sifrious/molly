@extends('molly::layout')
@section('title', 'Change impact')
@section('content')
<h1>Change impact</h1>
<p>Graph-backed change and impact overlay. Each section groups items by their relationship to the change: direct edits, affected neighbors, test coverage, unproven relationships, and visual changes. Unknown impact stays unknown.</p>
<livewire:molly-change-impact :delta-json="$deltaJson" />
@endsection

@extends('molly::layout')
@section('title', 'Create task')
@section('content')
<h1>Create task</h1>
<p>Saving a task does not start execution. Choose a trusted, disposable workspace.</p>
<form method="post" action="{{ route('molly.tasks.store') }}">
@csrf
<label for="prompt">What should Molly work on?</label><textarea id="prompt" name="prompt" maxlength="8192" aria-describedby="prompt-help">{{ old('prompt') }}</textarea>
<p class="hint" id="prompt-help">Describe one small change, or import a GitHub issue below. The imported issue replaces this prompt.</p>
<label for="issue_url">GitHub issue URL, optional</label><input id="issue_url" name="issue_url" type="url" value="{{ old('issue_url') }}" aria-describedby="issue-help">
<p class="hint" id="issue-help">Use https://github.com/OWNER/REPOSITORY/issues/NUMBER. Import reads the issue through your signed-in GitHub CLI.</p>
<label for="workspace">Workspace directory</label><input id="workspace" name="workspace" required value="{{ old('workspace', base_path()) }}">
<label for="paths">Files Molly may change</label><textarea id="paths" name="paths" required aria-describedby="paths-help">{{ old('paths') }}</textarea>
<p class="hint" id="paths-help">One repository-relative path per line. Include the required test file.</p>
<label for="test_path">Required Pest test file</label><input id="test_path" name="test_path" required value="{{ old('test_path') }}" aria-describedby="test-help">
<p class="hint" id="test-help">A PHP file under tests/, also listed above.</p>
<div class="actions"><flux:button type="submit" :loading="false">Save task</flux:button></div>
</form>
@endsection

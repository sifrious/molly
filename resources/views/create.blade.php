@extends('molly::layout')
@section('title', 'Create task')
@section('content')
<h1>Create task</h1>
<p>Saving a task does not start execution. Choose a trusted, disposable workspace.</p>
<form method="post" action="{{ route('molly.tasks.store') }}">
@csrf
<label for="nickname">Nickname, optional</label><input id="nickname" name="nickname" maxlength="64" value="{{ old('nickname') }}" aria-describedby="nickname-help">
<p class="hint" id="nickname-help">Choose a unique name such as hello-endpoint to use in task commands. Use letters, digits, or hyphens, starting with a letter. Molly stores nicknames in lowercase.</p>
<label for="prompt">What should Molly work on?</label><textarea id="prompt" name="prompt" maxlength="8192" aria-describedby="prompt-help">{{ old('prompt') }}</textarea>
<p class="hint" id="prompt-help">Describe one small change, or import a GitHub issue below. The imported issue replaces this prompt.</p>
<label for="issue_url">GitHub issue URL, optional</label><input id="issue_url" name="issue_url" type="url" value="{{ old('issue_url') }}" aria-describedby="issue-help">
<p class="hint" id="issue-help">Use https://github.com/OWNER/REPOSITORY/issues/NUMBER. Import reads the issue through your signed-in GitHub CLI.</p>
<label for="workspace">Workspace directory</label><input id="workspace" name="workspace" required value="{{ old('workspace', base_path()) }}">
<label for="test_path">Required Pest test file</label><input id="test_path" name="test_path" required value="{{ old('test_path') }}" aria-describedby="test-help">
<p class="hint" id="test-help">A PHP file under tests/. The implementation writer cannot change this test unless you opt in below.</p>
<label for="paths">Files Molly may change</label><textarea id="paths" name="paths" aria-describedby="paths-help">{{ old('paths') }}</textarea>
<p class="hint" id="paths-help">One repository-relative path per line. Do not include the required Pest test.</p>
<label><input type="checkbox" name="allow_test_edits" value="1" @checked(old('allow_test_edits'))> Allow this task to edit the required Pest test</label>
<p class="hint">This weaker trust model is not the default. The journal records the choice.</p>
<div class="actions"><flux:button type="submit" :loading="false">Save task</flux:button></div>
</form>
@endsection

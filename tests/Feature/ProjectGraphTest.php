<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\IndexProjectGraph;
use Sifrious\Molly\Actions\QueryProjectGraph;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-project-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    $this->knowledgeDatabase = sys_get_temp_dir().'/molly-project-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $this->knowledgeDatabase);
    $this->task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->run = $this->task->runs()->create([
        'prompt' => $this->task->prompt,
        'workspace' => $this->task->workspace,
        'status' => 'failed',
        'report' => [
            'verification_outcomes' => [
                'pest' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
                'tarpit' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            ],
            'completion_blockers' => ['pest'],
            'changes' => [['path' => 'app/Greeting.php', 'status' => 'modified']],
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
    File::delete($this->knowledgeDatabase);
});

it('indexes saved tasks, attempts, and verification blockers with provenance', function () {
    $indexed = app(IndexProjectGraph::class)->handle($this->workspace);
    $result = app(QueryProjectGraph::class)->handle($this->task->id, $this->workspace, depth: 2, limit: 40);

    expect($indexed['namespace'])->toBe('project')
        ->and($indexed['nodes'])->toBeGreaterThanOrEqual(5)
        ->and(array_column($result['nodes'], 'type'))->toContain('task', 'acceptance_test', 'run', 'blocker')
        ->and(array_column($result['edges'], 'relation'))->toContain('verified_by', 'blocked_by', 'changes');
    foreach ([...$result['nodes'], ...$result['edges']] as $record) {
        expect($record['sources'])->not->toBeEmpty();
    }
});

it('keeps Tarpit findings as evidence nodes rather than vague task labels', function () {
    app(IndexProjectGraph::class)->handle($this->workspace);
    $result = app(QueryProjectGraph::class)->handle('pest blocked completion', $this->workspace, depth: 1, limit: 20);

    expect(array_column($result['nodes'], 'type'))->toContain('blocker')
        ->and(array_column($result['nodes'], 'label'))->not->toContain('needs work');
});

it('indexes and queries the project graph through Artisan', function () {
    $exit = Artisan::call('molly:project:index', ['--workspace' => $this->workspace, '--json' => true]);
    $indexed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($indexed['nodes'])->toBeGreaterThan(0);

    $exit = Artisan::call('molly:project:query', ['concept' => $this->task->id, '--workspace' => $this->workspace, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and(array_column($result['nodes'], 'type'))->toContain('task');
});

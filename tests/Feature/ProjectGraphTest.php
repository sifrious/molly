<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\IndexProjectGraph;
use Sifrious\Molly\Actions\InspectProjectGraph;
use Sifrious\Molly\Actions\QueryProjectGraph;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSchema;
use Sifrious\Molly\Knowledge\GraphSource;

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

it('indexes a locked Pest digest as an approval edge', function () {
    $this->task->update([
        'source' => [
            'test_lock' => [
                'after_digest' => $this->task->test_digest,
                'before_digest' => 'old-digest',
                'approved_by' => 'human',
                'reason' => 'The greeting test now names the required Hello return.',
            ],
        ],
    ]);

    app(IndexProjectGraph::class)->handle($this->workspace);
    $result = app(QueryProjectGraph::class)->handle('Locked Pest test', $this->workspace, depth: 1, limit: 20);

    expect(array_column($result['nodes'], 'type'))->toContain('approval', 'acceptance_test')
        ->and(array_column($result['edges'], 'relation'))->toContain('approved_by');
});

it('lists blockers before other nodes in a bounded workspace overview', function () {
    $indexed = app(InspectProjectGraph::class)->handle($this->workspace);

    expect($indexed['namespace'])->toBe('project')
        ->and($indexed['truncated'])->toBeFalse()
        ->and($indexed['blockers'])->not->toBeEmpty()
        ->and($indexed['nodes'][0]['type'])->toBe('blocker')
        ->and(array_column($indexed['nodes'], 'type'))->toContain('task', 'acceptance_test', 'run');
    foreach ([...$indexed['nodes'], ...$indexed['edges']] as $record) {
        expect($record['sources'])->not->toBeEmpty();
    }
});

it('marks a workspace overview truncated after forty nodes', function () {
    $graph = new Graph(new GraphSchema, $this->knowledgeDatabase);
    $source = new GraphSource('project', 'overview', 'workspace', 'root', 'Overview', 'root', null, 'digest');
    $nodes = [];
    for ($index = 0; $index < 41; $index++) {
        $type = $index === 0 ? 'blocker' : ($index === 1 ? 'task' : 'file');
        $nodes[] = new GraphNode('project', 'overview', $type, 'node-'.$index, $type.' '.$index, [$source->id()]);
    }
    $graph->replace('project', 'overview', [$source], $nodes, []);

    $overview = $graph->overview('project', 'overview');

    expect($overview['truncated'])->toBeTrue()
        ->and($overview['nodes'])->toHaveCount(40)
        ->and($overview['nodes'][0]['type'])->toBe('blocker')
        ->and($overview['nodes'][1]['type'])->toBe('task');
    expect(fn () => $graph->overview('project', 'overview', 41))->toThrow(RuntimeException::class, 'KNOWLEDGE_LIMIT_INVALID');
});

it('indexes a recorded pull request and merge commit as evidence nodes', function () {
    $this->task->update([
        'source' => [
            'repository' => 'sifrious/molly',
            'issue_number' => 42,
            'issue_url' => 'https://github.com/sifrious/molly/issues/42',
            'linked_pr' => [
                'url' => 'https://github.com/sifrious/molly/pull/12',
                'number' => 12,
                'repository' => 'sifrious/molly',
                'merge_sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ],
        ],
    ]);

    app(IndexProjectGraph::class)->handle($this->workspace);
    $result = app(QueryProjectGraph::class)->handle('https://github.com/sifrious/molly/pull/12', $this->workspace, depth: 2, limit: 40);

    expect(array_column($result['nodes'], 'type'))->toContain('pull_request', 'commit', 'task', 'approval')
        ->and(array_column($result['edges'], 'relation'))->toContain('approved_by', 'produced');
});

it('indexes and queries the project graph through Artisan', function () {
    $exit = Artisan::call('molly:project:index', ['--workspace' => $this->workspace, '--json' => true]);
    $indexed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($indexed['nodes'])->toBeGreaterThan(0);

    $exit = Artisan::call('molly:project:query', ['concept' => $this->task->id, '--workspace' => $this->workspace, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and(array_column($result['nodes'], 'type'))->toContain('task');
});

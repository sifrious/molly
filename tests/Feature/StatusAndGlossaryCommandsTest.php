<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Sifrious\Molly\Journal\JournalRenderer;
use Sifrious\Molly\Models\Task;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['models' => []])]);
    $this->workspace = sys_get_temp_dir().'/molly-status-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->workspace);
    $this->workspace = realpath($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('reports readiness, open tasks with lease expiry, the worker, and effective settings as JSON', function (): void {
    $expired = Task::create(['prompt' => 'Fix the greeting', 'workspace' => $this->workspace, 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/GreetingTest.php', 'status' => 'running', 'worker_id' => 'worker-a', 'lease_expires_at' => now()->subMinute()]);
    $pending = Task::create(['prompt' => 'Add a farewell', 'workspace' => $this->workspace, 'paths' => ['app/Farewell.php'], 'test_path' => 'tests/FarewellTest.php']);
    Task::create(['prompt' => 'Elsewhere', 'workspace' => '/somewhere/else', 'paths' => ['a.php'], 'test_path' => 'tests/ATest.php']);
    Task::create(['prompt' => 'Done', 'workspace' => $this->workspace, 'paths' => ['b.php'], 'test_path' => 'tests/BTest.php', 'status' => 'completed']);

    $exit = Artisan::call('molly:status', ['--workspace' => $this->workspace, '--json' => true]);
    $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($status['status'])->toBe('ok')
        ->and($status['workspace'])->toBe($this->workspace)
        ->and($status['readiness'])->toHaveKeys(['ready', 'checks'])
        ->and(array_column($status['readiness']['checks'], 'code'))->toContain('pest_missing')
        ->and($status['tasks']['scope'])->toBe('workspace')
        ->and(array_column($status['tasks']['running'], 'id'))->toBe([$expired->id])
        ->and($status['tasks']['running'][0])->toMatchArray(['worker_id' => 'worker-a', 'lease_expired' => true])
        ->and($status['tasks']['running'][0]['lease_expires_at'])->not->toBeNull()
        ->and(array_column($status['tasks']['pending'], 'id'))->toBe([$pending->id])
        ->and($status['tasks']['pending'][0]['lease_expired'])->toBeNull()
        ->and($status['worker'])->toMatchArray(['action' => 'status', 'state' => 'stopped', 'pid' => null, 'alive' => false])
        ->and($status['config']['molly'])->toHaveKeys(['agent', 'max_attempts', 'agent_bus', 'verification', 'jev', 'worker'])
        ->and($status['config']['molly']['jev'])->not->toHaveKey('instructions')
        ->and($status['config']['queue'])->toHaveKeys(['connection', 'driver', 'queue', 'retry_after']);

    $exit = Artisan::call('molly:status', ['--json' => true]);
    $all = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($all['tasks']['scope'])->toBe('all_workspaces')
        ->and($all['tasks']['pending'])->toHaveCount(2);
});

it('does not change tasks or write worker files when reading status', function (): void {
    $task = Task::create(['prompt' => 'Fix it', 'workspace' => $this->workspace, 'paths' => ['a.php'], 'test_path' => 'tests/ATest.php']);
    $before = $task->fresh()->toArray();

    Artisan::call('molly:status', ['--workspace' => $this->workspace, '--json' => true]);

    expect($task->fresh()->toArray())->toBe($before)
        ->and(File::exists($this->workspace.'/.molly'))->toBeFalse();
});

it('exits 1 with a JSON error when status cannot be read', function (): void {
    expect(Artisan::call('molly:status', ['--workspace' => $this->workspace.'/missing', '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true)['error'])->toStartWith('WORKSPACE_INVALID');

    Schema::rename('molly_tasks', 'molly_tasks_unavailable');
    try {
        $exit = Artisan::call('molly:status', ['--workspace' => $this->workspace, '--json' => true]);
        $result = json_decode(Artisan::output(), true);
    } finally {
        Schema::rename('molly_tasks_unavailable', 'molly_tasks');
    }
    expect($exit)->toBe(1)->and($result['status'])->toBe('error');
});

it('prints a readable status report without --json', function (): void {
    expect(Artisan::call('molly:status', ['--workspace' => $this->workspace]))->toBe(0)
        ->and(Artisan::output())->toContain('Worker: stopped');
});

it('lists the managed glossary terms with provenance and links', function (): void {
    $exit = Artisan::call('molly:glossary', ['--workspace' => $this->workspace, '--json' => true]);
    $glossary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $terms = app(JournalRenderer::class)->glossaryTerms();

    expect($exit)->toBe(0)
        ->and($glossary['path'])->toBe($this->workspace.'/.molly/GLOSSARY.md')
        ->and($glossary['exported'])->toBeFalse()
        ->and(array_column($glossary['terms'], 'term'))->toBe(array_column($terms, 'term'))
        ->and(array_column($glossary['terms'], 'definition'))->toBe(array_column($terms, 'definition'))
        ->and($glossary['terms'][0])->toMatchArray([
            'id' => 'task',
            'term' => 'Task',
            'origin' => 'molly',
            'provenance' => ['source' => 'src/Journal/JournalRenderer.php', 'method' => 'JournalRenderer::glossaryTerms'],
        ])
        ->and($glossary['terms'][0]['links'])->toContain(['kind' => 'file', 'ref' => $this->workspace.'/.molly/GLOSSARY.md'])
        ->and(array_unique(array_column($glossary['terms'], 'id')))->toHaveCount(count($terms))
        ->and(File::exists($this->workspace.'/.molly'))->toBeFalse();
});

it('returns the same terms the journal export writes to GLOSSARY.md', function (): void {
    $renderer = app(JournalRenderer::class);
    File::ensureDirectoryExists($this->workspace.'/.molly');
    File::put($this->workspace.'/.molly/GLOSSARY.md', $renderer->replaceManagedGlossary(''));

    Artisan::call('molly:glossary', ['path' => $this->workspace, '--json' => true]);
    $glossary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $written = File::get($this->workspace.'/.molly/GLOSSARY.md');

    expect($glossary['exported'])->toBeTrue()
        ->and($glossary['workspace'])->toBe($this->workspace);
    foreach ($glossary['terms'] as $term) {
        expect($written)->toContain('- '.$term['term'].': '.$term['definition']);
    }
});

it('reports an invalid glossary workspace as a JSON error', function (): void {
    expect(Artisan::call('molly:glossary', ['--workspace' => $this->workspace.'/missing', '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true))->toMatchArray(['status' => 'error', 'terms' => []]);
});

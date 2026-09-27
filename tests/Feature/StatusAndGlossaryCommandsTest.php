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
            'provenance' => ['source' => 'package:sifrious/molly/src/Journal/JournalRenderer.php', 'method' => 'JournalRenderer::glossaryTerms'],
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

it('refreshes the project journal and glossary with molly:journal --project and no task', function (): void {
    $exit = Artisan::call('molly:journal', ['--project' => true, '--workspace' => $this->workspace, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['task_id'])->toBeNull()
        ->and($result['status'])->toBe('written')
        ->and($result['glossary_path'])->toBe($this->workspace.'/.molly/GLOSSARY.md')
        ->and(File::exists($this->workspace.'/.molly/JOURNAL.md'))->toBeTrue()
        ->and(File::get($this->workspace.'/.molly/GLOSSARY.md'))->toContain('<!-- molly:glossary:start -->');

    expect(Artisan::call('molly:journal', ['--json' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('TASK_REQUIRED');
});

/** Every glossary link must resolve from the workspace, or say it belongs to the package. */
function assertGlossaryLinksResolve(string $workspace): array
{
    Artisan::call('molly:glossary', ['--workspace' => $workspace, '--json' => true]);
    $glossary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $kinds = [];
    foreach ($glossary['terms'] as $term) {
        foreach ($term['links'] as $link) {
            $kinds[] = $link['kind'];
            if ($link['kind'] === 'package_source') {
                expect($link['ref'])->toStartWith('package:sifrious/molly/');

                continue;
            }
            $path = str_starts_with($link['ref'], '/') ? $link['ref'] : $workspace.'/'.$link['ref'];
            expect(is_file($path))->toBeTrue("{$link['kind']} link {$link['ref']} does not resolve from {$workspace}");
        }
        $provenance = $term['provenance']['source'];
        if (! str_starts_with($provenance, 'package:sifrious/molly/')) {
            expect(is_file($workspace.'/'.$provenance))->toBeTrue("provenance {$provenance} does not resolve from {$workspace}");
        }
    }

    return array_values(array_unique($kinds));
}

it('links glossary sources through vendor/sifrious/molly when Molly is installed in the workspace', function (): void {
    File::ensureDirectoryExists($this->workspace.'/vendor/sifrious');
    symlink(dirname(__DIR__, 2), $this->workspace.'/vendor/sifrious/molly');
    Artisan::call('molly:journal', ['--project' => true, '--workspace' => $this->workspace, '--json' => true]);

    expect(assertGlossaryLinksResolve($this->workspace))->toBe(['file', 'source']);

    Artisan::call('molly:glossary', ['--workspace' => $this->workspace, '--json' => true]);
    expect(json_decode(Artisan::output(), true)['terms'][0]['links'][1])
        ->toBe(['kind' => 'source', 'ref' => 'vendor/sifrious/molly/src/Journal/JournalRenderer.php']);
});

it('writes GLOSSARY.md source links that resolve from the .molly directory in an installed app', function (): void {
    File::ensureDirectoryExists($this->workspace.'/vendor/sifrious');
    symlink(dirname(__DIR__, 2), $this->workspace.'/vendor/sifrious/molly');
    Artisan::call('molly:journal', ['--project' => true, '--workspace' => $this->workspace, '--json' => true]);

    $glossary = $this->workspace.'/.molly/GLOSSARY.md';
    preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', File::get($glossary), $matches);

    expect($matches[1])->toHaveCount(count(app(JournalRenderer::class)->glossaryTerms()))
        ->and(array_unique($matches[1]))->toBe(['../vendor/sifrious/molly/src/Journal/JournalRenderer.php']);
    foreach ($matches[1] as $target) {
        expect(is_file(dirname($glossary).'/'.$target))->toBeTrue("{$target} does not resolve from {$glossary}");
    }
});

it('names the package source in GLOSSARY.md without a link when Molly is not under the workspace', function (): void {
    Artisan::call('molly:journal', ['--project' => true, '--workspace' => $this->workspace, '--json' => true]);
    $written = File::get($this->workspace.'/.molly/GLOSSARY.md');

    expect($written)->toContain('Source: `package:sifrious/molly/src/Journal/JournalRenderer.php`')
        ->and($written)->not->toMatch('/\]\(/');
});

it('scopes the glossary source link to the package when Molly is not under the workspace', function (): void {
    Artisan::call('molly:journal', ['--project' => true, '--workspace' => $this->workspace, '--json' => true]);

    expect(assertGlossaryLinksResolve($this->workspace))->toBe(['file', 'package_source']);
});

it('links glossary sources relative to a Molly checkout', function (): void {
    $checkout = realpath(dirname(__DIR__, 2));
    Artisan::call('molly:glossary', ['--workspace' => $checkout, '--json' => true]);
    $term = json_decode(Artisan::output(), true)['terms'][0];
    $link = $term['links'][1];

    expect($link)->toBe(['kind' => 'source', 'ref' => 'src/Journal/JournalRenderer.php'])
        ->and(is_file($checkout.'/'.$link['ref']))->toBeTrue()
        ->and($term['provenance']['source'])->toBe('src/Journal/JournalRenderer.php');
});

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CollectNativePhpKnowledge;
use Sifrious\Molly\Actions\CollectRunKnowledge;
use Sifrious\Molly\Actions\CollectTarpitKnowledge;
use Sifrious\Molly\Actions\IndexLaravelKnowledge;
use Sifrious\Molly\Actions\IndexNativePhpKnowledge;
use Sifrious\Molly\Actions\IndexTarpitKnowledge;
use Sifrious\Molly\Actions\QueryKnowledgeGraph;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphQuery;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Knowledge\LaravelVersion;

function knowledgeFixture(string $version = '13'): array
{
    $source = new GraphSource('laravel', $version, 'documentation', 'queues', 'Laravel queues', 'docs/queues.md', 'revision-'.$version, 'digest-'.$version);
    $queue = new GraphNode('laravel', $version, 'concept', 'queue', 'Queue', [$source->id()]);
    $job = new GraphNode('laravel', $version, 'concept', 'job', 'Job', [$source->id()]);
    $retry = new GraphNode('laravel', $version, 'concept', 'retry', 'Retry', [$source->id()]);
    $routing = new GraphNode('laravel', $version, 'concept', 'routing', 'Routing', [$source->id()]);

    return [
        'source' => $source,
        'nodes' => [$queue, $job, $retry, $routing],
        'edges' => [
            new GraphEdge('laravel', $version, 'related_to', $queue->id(), $job->id(), [$source->id()]),
            new GraphEdge('laravel', $version, 'uses', $job->id(), $retry->id(), [$source->id()]),
        ],
    ];
}

beforeEach(function () {
    $this->knowledgeDatabase = sys_get_temp_dir().'/molly-knowledge-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $this->knowledgeDatabase);
});

afterEach(function () {
    File::delete($this->knowledgeDatabase);
});

it('uses stable identities and keeps Laravel versions separate', function () {
    $twelve = knowledgeFixture('12');
    $thirteen = knowledgeFixture('13');

    expect($twelve['nodes'][0]->id())->toBe((knowledgeFixture('12'))['nodes'][0]->id())
        ->and($twelve['nodes'][0]->id())->not->toBe($thirteen['nodes'][0]->id());

    $graph = new Graph($this->knowledgeDatabase);
    foreach (['12' => $twelve, '13' => $thirteen] as $version => $fixture) {
        $graph->replace('laravel', $version, [$fixture['source']], $fixture['nodes'], $fixture['edges']);
    }

    $result = $graph->query(new GraphQuery('laravel', '12', 'Queue'));
    expect($result->version)->toBe('12');
    foreach ($result->nodes as $node) {
        foreach ($node['sources'] as $source) {
            expect($source['version'])->toBe('12')
                ->and($source['revision'])->toBe('revision-12');
        }
    }
});

it('replaces a snapshot without duplicating logical records', function () {
    $fixture = knowledgeFixture();
    $graph = new Graph($this->knowledgeDatabase);

    $first = $graph->replace('laravel', '13', [$fixture['source']], $fixture['nodes'], $fixture['edges']);
    $second = $graph->replace('laravel', '13', [$fixture['source']], $fixture['nodes'], $fixture['edges']);

    expect($first)->toBe(['sources' => 1, 'nodes' => 4, 'edges' => 2])
        ->and($second)->toBe($first)
        ->and($graph->counts('laravel', '13'))->toBe($first);
});

it('returns a bounded deterministic neighborhood with provenance', function () {
    $fixture = knowledgeFixture();
    $graph = new Graph($this->knowledgeDatabase);
    $graph->replace('laravel', '13', [$fixture['source']], $fixture['nodes'], $fixture['edges']);

    $query = new GraphQuery('laravel', '13', 'Queue', depth: 2, limit: 3);
    $first = $graph->query($query)->toArray();
    $second = $graph->query($query)->toArray();
    $partial = $graph->query(new GraphQuery('laravel', '13', 'ueu', depth: 0))->toArray();

    expect($first)->toBe($second)
        ->and(array_column($first['nodes'], 'label'))->toContain('Queue', 'Job', 'Retry')
        ->not->toContain('Routing')
        ->and($first['truncated'])->toBeFalse()
        ->and(array_column($partial['nodes'], 'label'))->toBe(['Queue']);
    foreach ([...$first['nodes'], ...$first['edges']] as $record) {
        expect($record['sources'])->not->toBeEmpty();
    }
});

it('respects relationship and size limits', function () {
    $fixture = knowledgeFixture();
    $graph = new Graph($this->knowledgeDatabase);
    $graph->replace('laravel', '13', [$fixture['source']], $fixture['nodes'], $fixture['edges']);

    $filtered = $graph->query(new GraphQuery('laravel', '13', 'Job', depth: 1, limit: 20, relations: ['uses']));
    $limited = $graph->query(new GraphQuery('laravel', '13', 'Queue', depth: 2, limit: 2));

    expect(array_column($filtered->nodes, 'label'))->toBe(['Job', 'Retry'])
        ->and(array_column($filtered->edges, 'relation'))->toBe(['uses'])
        ->and($limited->nodes)->toHaveCount(2)
        ->and($limited->truncated)->toBeTrue();
});

it('indexes the bundled queue guide and installed Laravel source', function () {
    $version = app(LaravelVersion::class)->current();
    $indexed = app(IndexLaravelKnowledge::class)->handle($version);
    $result = app(QueryKnowledgeGraph::class)->handle('Queue', $version, depth: 3, limit: 40);

    expect($indexed['sources'])->toBeGreaterThanOrEqual(15)
        ->and($indexed['nodes'])->toBeGreaterThanOrEqual(26)
        ->and($indexed['version'])->toBe($version)
        ->and(array_column($result['nodes'], 'label'))->toContain('Queue', 'Retry', 'ShouldQueue', 'QueueFake::assertPushed');
    $routing = app(QueryKnowledgeGraph::class)->handle('Route', $version, depth: 1, limit: 20);
    expect(array_column($routing['nodes'], 'label'))->toContain('Route');
    $testing = app(QueryKnowledgeGraph::class)->handle('Pest', $version, depth: 1, limit: 20);
    expect(array_column($testing['nodes'], 'label'))->toContain('Pest');
    $validation = app(QueryKnowledgeGraph::class)->handle('Validation', $version, depth: 1, limit: 20);
    expect(array_column($validation['nodes'], 'label'))->toContain('Validation', 'Validator');
    $container = app(QueryKnowledgeGraph::class)->handle('Container', $version, depth: 1, limit: 20);
    expect(array_column($container['nodes'], 'label'))->toContain('Container');
    $eloquent = app(QueryKnowledgeGraph::class)->handle('Eloquent', $version, depth: 1, limit: 20);
    expect(array_column($eloquent['nodes'], 'label'))->toContain('Eloquent', 'Model');
    $events = app(QueryKnowledgeGraph::class)->handle('Events', $version, depth: 1, limit: 20);
    expect(array_column($events['nodes'], 'label'))->toContain('Events', 'Dispatcher');
    foreach ([...$result['nodes'], ...$result['edges']] as $record) {
        expect($record['sources'])->not->toBeEmpty();
    }
});

it('indexes and queries through Artisan with JSON output', function () {
    $version = app(LaravelVersion::class)->current();
    expect(Artisan::call('molly:knowledge:index', ['namespace' => 'laravel', '--laravel-version' => $version, '--json' => true]))->toBe(0);
    $indexed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($indexed)->toMatchArray(['namespace' => 'laravel', 'version' => $version]);

    expect(Artisan::call('molly:knowledge:query', ['concept' => 'Queue', '--laravel-version' => $version, '--depth' => '1', '--json' => true]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($result['nodes'], 'label'))->toContain('Queue');
});

it('rejects a requested Laravel version that is not installed', function () {
    $installed = app(LaravelVersion::class)->current();
    $other = $installed === '13' ? '12' : '13';

    expect(fn () => app(IndexLaravelKnowledge::class)->handle($other))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_VERSION_MISMATCH');
});

it('indexes NativePHP desktop and mobile as separate versions', function () {
    $indexed = app(IndexNativePhpKnowledge::class)->handle();
    $desktop = app(QueryKnowledgeGraph::class)->handle('Desktop', 'desktop-2', 1, 20, [], 'nativephp');
    $mobile = app(QueryKnowledgeGraph::class)->handle('Mobile', 'mobile-4', 1, 20, [], 'nativephp');

    expect($indexed)->toMatchArray(['namespace' => 'nativephp', 'versions' => ['desktop-2', 'mobile-4']])
        ->and($indexed['sources'])->toBe(2)
        ->and(array_column($desktop['nodes'], 'label'))->toContain('NativePHP', 'Desktop')
        ->not->toContain('Mobile')
        ->and($desktop['namespace'])->toBe('nativephp')
        ->and($desktop['version'])->toBe('desktop-2')
        ->and(array_column($mobile['nodes'], 'label'))->toContain('NativePHP', 'Mobile')
        ->not->toContain('Desktop')
        ->and($mobile['version'])->toBe('mobile-4');
    foreach ([...$desktop['nodes'], ...$desktop['edges'], ...$mobile['nodes'], ...$mobile['edges']] as $record) {
        expect($record['sources'])->not->toBeEmpty();
    }
});

it('indexes and queries NativePHP through Artisan with JSON output', function () {
    expect(Artisan::call('molly:knowledge:index', ['namespace' => 'nativephp', '--json' => true]))->toBe(0);
    $indexed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($indexed)->toMatchArray(['namespace' => 'nativephp', 'versions' => ['desktop-2', 'mobile-4']]);

    expect(Artisan::call('molly:knowledge:query', [
        'concept' => 'Mobile', '--namespace' => 'nativephp', '--nativephp-version' => 'mobile-4', '--depth' => '1', '--json' => true,
    ]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['namespace'])->toBe('nativephp')
        ->and($result['version'])->toBe('mobile-4')
        ->and(array_column($result['nodes'], 'label'))->toContain('Mobile')
        ->not->toContain('Desktop');
});

it('rejects NativePHP index with a Laravel version flag', function () {
    expect(Artisan::call('molly:knowledge:index', ['namespace' => 'nativephp', '--laravel-version' => '13', '--json' => true]))->toBe(1);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['error'])->toStartWith('KNOWLEDGE_VERSION_INVALID');
});

it('collects NativePHP knowledge only when the task names a track', function () {
    $mobile = app(CollectNativePhpKnowledge::class)->handle(
        'Add a NativePHP mobile task list.',
        ['app/TaskList.php' => null],
        'tests/TaskListTest.php',
    );
    $omitted = app(CollectNativePhpKnowledge::class)->handle(
        'Validate the queued greeting route.',
        ['app/Greeting.php' => null],
        'tests/GreetingTest.php',
    );

    expect($mobile['status'])->toBeIn(['advisory', 'unavailable'])
        ->and($mobile['concepts'])->toBe(['Mobile'])
        ->and($omitted['status'])->toBe('omitted')
        ->and($omitted['concepts'])->toBe([]);
});

it('indexes tarpit notes without mixing Laravel or NativePHP', function () {
    $indexed = app(IndexTarpitKnowledge::class)->handle();
    $result = app(QueryKnowledgeGraph::class)->handle('Tarpit', null, 1, 20, [], 'tarpit');

    expect($indexed['namespace'])->toBe('tarpit')
        ->and($indexed['version'])->toStartWith('notes-')
        ->and($indexed['sources'])->toBe(1)
        ->and($result['namespace'])->toBe('tarpit')
        ->and($result['version'])->toBe($indexed['version'])
        ->and(array_column($result['nodes'], 'label'))->toContain('Tarpit', 'Complexity')
        ->not->toContain('Queue', 'Desktop', 'Mobile');
    foreach ([...$result['nodes'], ...$result['edges']] as $record) {
        expect($record['sources'])->not->toBeEmpty();
    }
});

it('indexes and queries tarpit notes through Artisan with JSON output', function () {
    expect(Artisan::call('molly:knowledge:index', ['namespace' => 'tarpit', '--json' => true]))->toBe(0);
    $indexed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($indexed['namespace'])->toBe('tarpit')->and($indexed['version'])->toStartWith('notes-');

    expect(Artisan::call('molly:knowledge:query', [
        'concept' => 'Cleverness', '--namespace' => 'tarpit', '--depth' => '1', '--json' => true,
    ]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['namespace'])->toBe('tarpit')
        ->and(array_column($result['nodes'], 'label'))->toContain('Cleverness');
});

it('rejects tarpit index with a Laravel version flag', function () {
    expect(Artisan::call('molly:knowledge:index', ['namespace' => 'tarpit', '--laravel-version' => '13', '--json' => true]))->toBe(1);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['error'])->toStartWith('KNOWLEDGE_VERSION_INVALID');
});

it('collects tarpit knowledge only when the task names those notes', function () {
    $named = app(CollectTarpitKnowledge::class)->handle(
        'Reduce accidental complexity in the greeting.',
        ['app/Greeting.php' => null],
        'tests/GreetingTest.php',
    );
    $omitted = app(CollectTarpitKnowledge::class)->handle(
        'Validate the queued greeting route.',
        ['app/Greeting.php' => null],
        'tests/GreetingTest.php',
    );

    expect($named['status'])->toBeIn(['advisory', 'unavailable'])
        ->and($named['concepts'])->toContain('Accident')
        ->and($omitted['status'])->toBe('omitted')
        ->and($omitted['concepts'])->toBe([]);
});

it('collects bounded advisory knowledge for a run without requiring an index', function () {
    $context = app(CollectRunKnowledge::class)->handle(
        'Validate the queued greeting route.',
        ['app/Greeting.php' => null, 'routes/web.php' => null],
        'tests/GreetingTest.php',
    );

    expect($context['status'])->toBeIn(['advisory', 'unavailable'])
        ->and($context['neighborhoods'])->toBeArray()
        ->and($context['concepts'])->toContain('Validation', 'Queue', 'Route');
});

it('does not require a Burdgen package at runtime', function () {
    $composer = json_decode(File::get(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_keys([...$composer['require'], ...$composer['require-dev']]);

    expect($packages)->not->toContain('sifrious/burdgen')
        ->and(implode(' ', $packages))->not->toContain('burdgen');
});

it('builds a stable ContextPack DTO with selection reasons and no fabricated queue default', function () {
    $withQueue = app(CollectRunKnowledge::class)->pack(
        'Validate the queued greeting route.',
        ['app/Greeting.php' => null, 'routes/web.php' => null],
        'tests/GreetingTest.php',
    )->toArray();

    expect($withQueue['schema'])->toBe(\Sifrious\Molly\Knowledge\ContextPack::SCHEMA)
        ->and($withQueue['status'])->toBeIn(['advisory', 'unavailable'])
        ->and($withQueue['query']['concepts'])->toContain('Validation', 'Queue', 'Route')
        ->and($withQueue['items'])->not->toBeEmpty()
        ->and($withQueue['items'][0])->toHaveKeys(['concept', 'selection_reason', 'provenance', 'matched', 'truncated', 'nodes', 'edges', 'sources']);

    foreach ($withQueue['items'] as $item) {
        expect($item['selection_reason'])->toContain('Matched needle');
    }

    $unrelated = app(CollectRunKnowledge::class)->pack(
        'Return a plain greeting string.',
        ['app/Greeting.php' => null],
        'tests/GreetingTest.php',
    )->toArray();

    // "test" needle in GreetingTest.php selects Pest — Queue must not appear merely as a global default.
    expect($unrelated['concepts'])->not->toContain('Queue')
        ->and($unrelated['query']['concepts'])->not->toContain('Queue');
});

it('returns an empty ContextPack when no needles match and does not invent Queue', function () {
    $pack = app(CollectRunKnowledge::class)->pack(
        'Say hello to the stranger.',
        ['app/Greeting.php' => null],
        'resources/views/home.blade.php',
    )->toArray();

    expect($pack['status'])->toBeIn(['empty', 'unavailable'])
        ->and($pack['concepts'])->toBe([])
        ->and($pack['items'])->toBe([])
        ->and($pack['query']['needles'])->toBe([])
        ->and($pack['concepts'])->not->toContain('Queue');
});

it('reproduces the same ContextPack for identical inputs against the same graph snapshot', function () {
    $prompt = 'Dispatch a queued job for the greeting.';
    $files = ['app/Jobs/SendGreeting.php' => null];
    $test = 'tests/Feature/SendGreetingTest.php';

    $first = app(CollectRunKnowledge::class)->pack($prompt, $files, $test)->toArray();
    $second = app(CollectRunKnowledge::class)->pack($prompt, $files, $test)->toArray();

    expect($first['query'])->toBe($second['query'])
        ->and($first['concepts'])->toBe($second['concepts'])
        ->and(array_column($first['items'], 'selection_reason'))->toBe(array_column($second['items'], 'selection_reason'))
        ->and(array_column($first['items'], 'concept'))->toBe(array_column($second['items'], 'concept'));
});

it('enforces the neighborhood node limit on ContextPack items', function () {
    $pack = app(CollectRunKnowledge::class)->pack(
        'Work the queue job retry path.',
        ['app/Jobs/Example.php' => null],
        'tests/Feature/ExampleTest.php',
    )->toArray();

    if ($pack['status'] !== 'advisory') {
        expect($pack['status'])->toBeIn(['empty', 'unavailable']);

        return;
    }

    foreach ($pack['items'] as $item) {
        expect(count($item['nodes']))->toBeLessThanOrEqual(8)
            ->and($item['provenance']['limit'])->toBe(8);
    }
});

it('prints ContextPack debug details through molly:knowledge:pack', function () {
    $exit = Artisan::call('molly:knowledge:pack', [
        'prompt' => 'Validate the queued greeting route.',
        '--file' => ['app/Greeting.php', 'routes/web.php'],
        '--test' => 'tests/GreetingTest.php',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($payload['schema'])->toBe(\Sifrious\Molly\Knowledge\ContextPack::SCHEMA)
        ->and($payload['query']['needles'])->not->toBeEmpty()
        ->and($payload['concepts'])->toContain('Queue');
});

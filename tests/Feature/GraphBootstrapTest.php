<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Sifrious\Molly\Actions\RetryProjectKnowledgeGraphUnit;
use Sifrious\Molly\Knowledge\ComposerLock;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphCache;
use Sifrious\Molly\PlanningGuide;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->knowledgeDatabase = sys_get_temp_dir().'/molly-knowledge-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $this->knowledgeDatabase);

    $this->project = sys_get_temp_dir().'/molly-graph-project-'.Str::uuid();
    File::ensureDirectoryExists($this->project);
    File::put($this->project.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->project.'/composer.json', json_encode([
        'name' => 'example/app',
        'require' => ['laravel/framework' => '^12.0'],
    ], JSON_PRETTY_PRINT));
});

afterEach(function (): void {
    File::deleteDirectory($this->mollyHome);
    File::deleteDirectory($this->project);
    File::delete($this->knowledgeDatabase);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

function writeLock(string $root, string $laravelVersion, array $extraPackages = []): void
{
    $packages = array_merge([
        ['name' => 'laravel/framework', 'version' => $laravelVersion],
    ], $extraPackages);
    File::put($root.'/composer.lock', json_encode([
        'packages' => $packages,
        'packages-dev' => [],
    ], JSON_PRETTY_PRINT));
}

function makeBootstrap(GraphCache $cache, Graph $graph): BootstrapProjectKnowledgeGraphs
{
    app()->instance(GraphCache::class, $cache);
    app()->instance(Graph::class, $graph);

    return app(BootstrapProjectKnowledgeGraphs::class);
}

it('reads exact laravel version from composer.lock', function (): void {
    writeLock($this->project, 'v12.3.1');
    $lock = (new ComposerLock)->read($this->project);
    expect($lock['laravel'])->toBe('12.3.1')
        ->and($lock['laravel_major'])->toBe('12');
});

it('refuses a cache entry from a different exact dependency version', function (): void {
    $cache = new GraphCache($this->mollyHome.'/graph-cache');
    $cache->put('laravel', 'laravel/framework', '12.0.0', [
        'graph_version_key' => '12',
        'sources' => [],
        'nodes' => [],
        'edges' => [],
    ]);

    expect($cache->get('laravel', 'laravel/framework', '12.0.0'))->not->toBeNull()
        ->and($cache->get('laravel', 'laravel/framework', '12.3.1'))->toBeNull();
});

it('bootstraps a mandatory laravel graph and writes provenance', function (): void {
    writeLock($this->project, 'v12.0.0');

    $result = makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    )->handle($this->project);

    expect($result['ok'])->toBeTrue()
        ->and($result['laravel_exact'])->toBe('12.0.0')
        ->and(File::exists($result['manifest_path']))->toBeTrue();

    $manifest = json_decode(File::get($result['manifest_path']), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['laravel_exact'])->toBe('12.0.0')
        ->and($manifest['laravel_major'])->toBe(12)
        ->and($manifest['schema_version'])->toBe(BootstrapProjectKnowledgeGraphs::MANIFEST_SCHEMA);

    $laravel = collect($manifest['units'])->firstWhere('id', 'laravel:laravel/framework');
    expect($laravel['status'])->toBe('ready')
        ->and($laravel['exact_version'])->toBe('12.0.0')
        ->and($laravel['source'])->toBeIn(['built', 'cache'])
        ->and($laravel['ingested_at'])->not->toBeEmpty();

    $counts = (new Graph($this->knowledgeDatabase))->counts('laravel', '12');
    expect($counts['nodes'])->toBeGreaterThan(0);
});

it('reuses exact-version cache on a second project with the same lock version', function (): void {
    writeLock($this->project, 'v12.0.0');
    $cache = new GraphCache($this->mollyHome.'/graph-cache');
    $graph = new Graph($this->knowledgeDatabase);
    $bootstrap = makeBootstrap($cache, $graph);

    $first = $bootstrap->handle($this->project);
    $laravelFirst = collect($first['units'])->firstWhere('id', 'laravel:laravel/framework');
    expect($laravelFirst['source'])->toBe('built');

    $secondRoot = sys_get_temp_dir().'/molly-graph-project-'.Str::uuid();
    File::ensureDirectoryExists($secondRoot);
    File::put($secondRoot.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($secondRoot.'/composer.json', File::get($this->project.'/composer.json'));
    writeLock($secondRoot, 'v12.0.0');

    $second = $bootstrap->handle($secondRoot);
    $laravelSecond = collect($second['units'])->firstWhere('id', 'laravel:laravel/framework');
    expect($laravelSecond['source'])->toBe('cache')
        ->and($laravelSecond['exact_version'])->toBe('12.0.0');

    File::deleteDirectory($secondRoot);
});

it('fails when laravel/framework is missing from the lockfile', function (): void {
    File::put($this->project.'/composer.lock', json_encode([
        'packages' => [['name' => 'some/other', 'version' => '1.0.0']],
        'packages-dev' => [],
    ], JSON_PRETTY_PRINT));

    expect(fn () => makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    )->handle($this->project))->toThrow(RuntimeException::class, 'LARAVEL_VERSION_MISSING');
});

it('retries a single unit via RetryProjectKnowledgeGraphUnit', function (): void {
    writeLock($this->project, 'v12.0.0');
    $bootstrap = makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    );
    $bootstrap->handle($this->project);

    $retry = (new RetryProjectKnowledgeGraphUnit($bootstrap))->handle(
        $this->project,
        'laravel:laravel/framework',
    );

    expect($retry['retried_unit'])->toBe('laravel:laravel/framework')
        ->and(collect($retry['units'])->firstWhere('id', 'laravel:laravel/framework')['status'])->toBe('ready');
});

it('indexes the project workspace graph unit', function (): void {
    writeLock($this->project, 'v12.0.0');

    $result = makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    )->handle($this->project);

    $project = collect($result['units'])->firstWhere('id', 'project:workspace');
    expect($project)->not->toBeNull()
        ->and($project['status'])->toBe('ready')
        ->and($project['source'])->toBe('built')
        ->and($project['namespace'])->toBe('project');
});

it('reports progress in lockfile then laravel then project then manifest order', function (): void {
    writeLock($this->project, 'v12.0.0');
    $steps = [];

    makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    )->handle($this->project, function (string $step, string $message) use (&$steps): void {
        $steps[] = $step;
    });

    $unique = [];
    foreach ($steps as $step) {
        if (! in_array($step, $unique, true)) {
            $unique[] = $step;
        }
    }

    expect($unique[0] ?? null)->toBe('lockfile')
        ->and($unique)->toContain('laravel')
        ->and($unique)->toContain('project')
        ->and($unique[array_key_last($unique)] ?? null)->toBe('manifest');

    $lockIdx = array_search('lockfile', $unique, true);
    $laravelIdx = array_search('laravel', $unique, true);
    $projectIdx = array_search('project', $unique, true);
    $manifestIdx = array_search('manifest', $unique, true);
    expect($lockIdx)->toBeLessThan($laravelIdx)
        ->and($laravelIdx)->toBeLessThan($projectIdx)
        ->and($projectIdx)->toBeLessThan($manifestIdx);
});

it('preserves sibling units in the manifest when retrying one unit', function (): void {
    writeLock($this->project, 'v12.0.0');
    $bootstrap = makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    );
    $first = $bootstrap->handle($this->project);
    $beforeIds = collect($first['units'])->pluck('id')->sort()->values()->all();

    $retry = (new RetryProjectKnowledgeGraphUnit($bootstrap))->handle(
        $this->project,
        'laravel:laravel/framework',
    );
    $afterIds = collect($retry['units'])->pluck('id')->sort()->values()->all();

    expect($afterIds)->toBe($beforeIds)
        ->and($afterIds)->toContain('laravel:laravel/framework')
        ->and($afterIds)->toContain('project:workspace');
});

it('keeps deterministic laravel snapshot node counts across fresh builds', function (): void {
    writeLock($this->project, 'v12.0.0');
    $cacheDir = $this->mollyHome.'/graph-cache-a';
    $dbA = sys_get_temp_dir().'/molly-knowledge-a-'.Str::uuid().'.sqlite';
    $dbB = sys_get_temp_dir().'/molly-knowledge-b-'.Str::uuid().'.sqlite';

    $first = makeBootstrap(new GraphCache($cacheDir.'-1'), new Graph($dbA))->handle($this->project);
    $secondProject = sys_get_temp_dir().'/molly-graph-project-'.Str::uuid();
    File::ensureDirectoryExists($secondProject);
    File::put($secondProject.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($secondProject.'/composer.json', File::get($this->project.'/composer.json'));
    writeLock($secondProject, 'v12.0.0');
    $second = makeBootstrap(new GraphCache($cacheDir.'-2'), new Graph($dbB))->handle($secondProject);

    $firstLaravel = collect($first['units'])->firstWhere('id', 'laravel:laravel/framework');
    $secondLaravel = collect($second['units'])->firstWhere('id', 'laravel:laravel/framework');
    expect($firstLaravel['source'])->toBe('built')
        ->and($secondLaravel['source'])->toBe('built')
        ->and($firstLaravel['counts'])->toBe($secondLaravel['counts']);

    File::deleteDirectory($secondProject);
    File::delete($dbA);
    File::delete($dbB);
});

it('records optional NativePHP unit failure without aborting laravel bootstrap', function (): void {
    writeLock($this->project, 'v12.0.0', [
        ['name' => 'nativephp/desktop', 'version' => '1.2.3'],
    ]);

    app()->instance(PlanningGuide::class, new class extends PlanningGuide
    {
        public function source(string $id): array
        {
            if ($id === 'nativephp-desktop') {
                throw new RuntimeException('NATIVEPHP_GRAPH_FAIL');
            }

            return parent::source($id);
        }
    });

    $result = makeBootstrap(
        new GraphCache($this->mollyHome.'/graph-cache'),
        new Graph($this->knowledgeDatabase),
    )->handle($this->project);

    $laravel = collect($result['units'])->firstWhere('id', 'laravel:laravel/framework');
    $nativeUnit = collect($result['units'])->firstWhere('id', 'nativephp:nativephp/desktop');
    expect($laravel['status'])->toBe('ready')
        ->and($nativeUnit['status'])->toBe('failed')
        ->and($nativeUnit['error'])->toContain('NATIVEPHP_GRAPH_FAIL')
        ->and($result['ok'])->toBeFalse();
});

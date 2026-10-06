<?php

use Illuminate\Support\Facades\File;

function seamHttpContract(): array
{
    return ['schema_version' => 1, 'name' => 'Readiness', 'symbol' => 'App\\Http\\Controllers\\ReadyController',
        'path' => 'app/Http/Controllers/ReadyController.php', 'target_state' => 'planned',
        'test_path' => 'tests/Feature/ReadinessTest.php', 'production_paths' => ['app/Http/Controllers/ReadyController.php', 'routes/web.php'],
        'accepted_state' => [], 'fixtures' => [],
        'cases' => [['id' => 'ready', 'description' => 'reports readiness', 'method' => 'GET', 'uri' => '/ready', 'input' => [],
            'expected' => ['status' => 200, 'json' => ['ready' => true]], 'before' => ['status' => 404]]],
        'negative_control' => ['path' => 'app/Http/Controllers/ReadyController.php', 'find' => "'ready' => true", 'replace' => "'ready' => false", 'case_id' => 'ready']];
}

function seamApplicationFixture(): string
{
    $workspace = laravelShapedWorkspace();
    File::put($workspace.'/.gitignore', "/.molly/\n/.phpunit.cache/\n/.phpunit.result.cache\n/storage/\n");
    File::put($workspace.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']]]));
    File::put($workspace.'/artisan', '<?php // Test fixture entry point.');
    commitGitWorkspace($workspace, 'Prepare seam fixture');

    return $workspace;
}

function seamConsoleContract(): array
{
    $contract = seamHttpContract();
    $contract['symbol'] = 'App\\Console\\ReadyCommand';
    $contract['path'] = 'app/Console/ReadyCommand.php';
    $contract['production_paths'] = [$contract['path']];
    $contract['target_state'] = 'existing';
    $contract['cases'] = [['id' => 'ready', 'description' => 'reports readiness', 'command' => 'readiness:check', 'arguments' => [],
        'expected' => ['output' => 'Ready', 'exit' => 0], 'before' => ['output' => 'Not ready', 'exit' => 1]]];
    $contract['negative_control'] = ['path' => $contract['path'], 'find' => "'Ready'", 'replace' => "'Not ready'", 'case_id' => 'ready'];

    return $contract;
}

function prepareSeamConsoleFixture(string $workspace): void
{
    File::ensureDirectoryExists($workspace.'/app/Console');
    File::put($workspace.'/app/Console/ReadyCommand.php', "<?php\nnamespace App\\Console;\nclass ReadyCommand extends \\Illuminate\\Console\\Command { protected \$signature = 'readiness:check'; public function handle() { \$this->line('Not ready'); return 1; } }\n");
    File::put($workspace.'/tests/SeamServiceProvider.php', "<?php\nnamespace Tests;\nclass SeamServiceProvider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { \$this->commands([\\App\\Console\\ReadyCommand::class]); } }\n");
    $case = File::get($workspace.'/tests/TestCase.php');
    $case = str_replace('protected function defineEnvironment', 'protected function getPackageProviders($app): array { return [SeamServiceProvider::class]; }'."\n\n    ".'protected function defineEnvironment', $case);
    File::put($workspace.'/tests/TestCase.php', $case);
    commitGitWorkspace($workspace, 'Register the baseline command');
}

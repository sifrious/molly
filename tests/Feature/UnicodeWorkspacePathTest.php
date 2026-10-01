<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Symfony\Component\Process\Process;

/*
 * M03.7: the main commands work in a workspace whose path has spaces, a quote, and non-ASCII characters.
 * The workspace is shaped like a Laravel app with its own Pest entry point, so molly:start runs
 * the real Pest binary, Git, and the Clever measurements against that path. Only the model is faked.
 */

function unicodeWorkspace(string $workspace): string
{
    $vendor = dirname(__DIR__, 2).'/vendor';
    foreach (['app', 'routes', 'tests/Feature', 'vendor/bin', 'vendor/pestphp/pest/bin'] as $directory) {
        File::ensureDirectoryExists($workspace.'/'.$directory);
    }
    File::put($workspace.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($workspace.'/composer.json', json_encode(['name' => 'example/app', 'require' => ['laravel/framework' => '^12.0']], JSON_PRETTY_PRINT)."\n");
    File::put($workspace.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.0']], 'packages-dev' => []], JSON_PRETTY_PRINT)."\n");
    File::put($workspace.'/.gitignore', "/vendor\n");
    File::put($workspace.'/routes/web.php', '<?php');
    File::put($workspace.'/app/Greeting.php', "<?php\n\nnamespace App;\n\nclass Greeting\n{\n    public function text(): string\n    {\n        return 'Hi';\n    }\n}\n");
    File::put($workspace.'/tests/Feature/GreetingTest.php', "<?php\n\nit('greets', function () {\n    expect((new App\\Greeting)->text())->toBe('Hello');\n});\n");
    File::copy($vendor.'/pestphp/pest/bin/pest', $workspace.'/vendor/pestphp/pest/bin/pest');
    File::put($workspace.'/vendor/bin/pest', "<?php\n\ninclude __DIR__.'/../pestphp/pest/bin/pest';\n");
    File::put($workspace.'/vendor/autoload.php', "<?php\n\n\$loader = require ".var_export($vendor.'/autoload.php', true).";\n\$loader->addPsr4('App\\\\', __DIR__.'/../app/');\n\nreturn \$loader;\n");
    File::put($workspace.'/phpunit.xml', '<?xml version="1.0" encoding="UTF-8"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="Workspace"><directory>tests</directory></testsuite></testsuites></phpunit>');
    File::put($workspace.'/tests/Pest.php', "<?php\n");
    commitGitWorkspace($workspace);

    return str_replace('\\', '/', realpath($workspace));
}

/** @return array{0: int, 1: array<string, mixed>} */
function unicodeJson(string $command, array $parameters): array
{
    $exit = Artisan::call($command, [...$parameters, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function cleanReview(): array
{
    return ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the selected files.']), 'findings' => []];
}

beforeEach(function (): void {
    // Under /tmp so Pest's path-derived test class names stay short on every host.
    $this->parent = "/tmp/Molly's Tëst ✓ ".bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->parent);
    // The registry, graph cache, and knowledge database live under the same kind of path.
    $this->mollyHome = $this->parent.'/home ✓';
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;
    $this->knowledgeDatabase = $this->parent.'/knowledge ✓.sqlite';
    config([
        'molly.knowledge.database' => $this->knowledgeDatabase,
        'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
        'molly.agent' => 'ollama',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->parent);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

it('initializes, creates, runs, shows, journals, and hands off a task in a path with spaces, a quote, and Unicode', function (): void {
    $workspace = unicodeWorkspace($this->parent.'/app');

    [$exit, $init] = unicodeJson('molly:project-init', ['path' => $this->parent.'/app', '--no-composer' => true, '--no-migrate' => true]);
    expect($exit)->toBe(0, (string) ($init['error'] ?? ''))
        ->and($init['project']['path'])->toBe($workspace)
        ->and($init['graphs']['ok'])->toBeTrue()
        ->and(json_decode(File::get($this->mollyHome.'/projects.json'), true))->toBe([$workspace]);

    [$exit, $created] = unicodeJson('molly:create', [
        'prompt' => 'Return Hello from App\Greeting::text().',
        '--workspace' => $this->parent.'/app',
        '--file' => ['app/Greeting.php'],
        '--test' => 'tests/Feature/GreetingTest.php',
        '--name' => 'greeting',
    ]);
    expect($exit)->toBe(0, (string) ($created['error'] ?? ''))
        ->and($created['task']['workspace'])->toBe($workspace);

    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => str_replace("'Hi'", "'Hello'", File::get($workspace.'/app/Greeting.php'))]]]])->preventStrayPrompts();
    TarpitReviewer::fake([cleanReview()])->preventStrayPrompts();

    [$exit, $started] = unicodeJson('molly:start', ['task' => 'greeting']);
    $report = $started['report'];
    expect($exit)->toBe(0, json_encode($report['verification'] ?? $report))
        ->and($started['status'])->toBe('completed')
        ->and($report['verification']['status'])->toBe('passed')
        ->and($report['verification']['command'][1])->toBe($workspace.'/vendor/bin/pest')
        ->and($report['verification']['output'])->toContain('Configuration: '.$workspace.'/phpunit.xml')
        ->and(array_column($report['complexity_before']['probes'], 'status'))->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and(array_column($report['complexity_after']['probes'], 'status'))->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and(File::get($workspace.'/app/Greeting.php'))->toContain("return 'Hello';");

    [$exit, $shown] = unicodeJson('molly:show', ['run' => $started['id']]);
    expect($exit)->toBe(0)->and($shown['status'])->toBe('completed');
    Artisan::call('molly:show', ['run' => $started['id']]);
    expect(Artisan::output())->toContain('Workspace: '.$workspace);

    [$exit, $journal] = unicodeJson('molly:journal', ['task' => 'greeting']);
    expect($exit)->toBe(0)
        ->and($journal['path'])->toBe($workspace.'/.molly/journal/'.$started['task_id'].'.md')
        ->and($journal['attempt_count'])->toBe(1)
        ->and(Str::markdown(File::get($journal['path'])))->toContain('Run UUID: '.$started['id']);

    [$exit, $project] = unicodeJson('molly:journal', ['--project' => true, '--workspace' => $this->parent.'/app']);
    expect($exit)->toBe(0)
        ->and($project['journal_path'])->toBe($workspace.'/.molly/JOURNAL.md')
        ->and(File::exists($project['journal_path']))->toBeTrue();

    [$exit, $handoff] = unicodeJson('molly:handoff', ['task' => 'greeting', '--from' => (string) Str::uuid(), '--to' => (string) Str::uuid(), '--approve' => true]);
    expect($exit)->toBe(0)
        ->and($handoff['allowed_paths'])->toBe(['app/Greeting.php'])
        ->and($handoff['prior_diagnostics'])->toContain('pest:passed');

    AcceptanceWriter::fake([['criteria' => ['A visitor sees Hello.'], 'files' => ['app/Greeting.php']]])->preventStrayPrompts();
    [$exit, $story] = unicodeJson('molly:story', ['story' => 'Visitors see Hello.', '--workspace' => $this->parent.'/app', '--test' => 'tests/Feature/StoryTest.php', '--name' => 'story']);
    expect($exit)->toBe(0, (string) ($story['error'] ?? ''))
        ->and($story['task']['workspace'])->toBe($workspace);

    ChangeWriter::fake([['summary' => 'Say Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => File::get($workspace.'/app/Greeting.php')."\n"]]]])->preventStrayPrompts();
    TarpitReviewer::fake([cleanReview()])->preventStrayPrompts();
    [$exit, $run] = unicodeJson('molly:run', ['prompt' => 'Keep returning Hello.', '--workspace' => $this->parent.'/app', '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php']);
    expect($exit)->toBe(0, json_encode($run['report']['verification'] ?? $run))
        ->and($run['status'])->toBe('completed');
});

it('runs the commands molly:project-new prints through a shell for a path with spaces, a quote, and Unicode', function (bool $insideRepository): void {
    if ($insideRepository) {
        File::put($this->parent.'/README.md', "# monorepo\n");
        commitGitWorkspace($this->parent);
    }
    $target = $this->parent."/Tëst's app ✓";

    [$exit, $created] = unicodeJson('molly:project-new', ['path' => $target, '--no-composer' => true]);
    expect($exit)->toBe(0)
        ->and($created['status'])->toBe('needs_commit')
        ->and(end($created['next']))->toBe('php artisan molly:project-init '.escapeshellarg($target));

    // The user's shell runs the printed commands. php artisan is this package's Testbench, and
    // --no-composer keeps molly:project-init from asking Composer for a release over the network.
    $artisan = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/vendor/bin/testbench');
    $commands = array_map(fn (string $command): string => (string) preg_replace('/\Aphp artisan (.+)\z/', $artisan.' $1 --no-composer', $command), $created['next']);
    $script = implode(' && ', $commands);
    $shell = new Process(['/bin/sh', '-c', $script], dirname(__DIR__, 2), [
        ...testbenchProcess([])->getEnv(),
        'MOLLY_HOME' => $this->mollyHome,
        'MOLLY_KNOWLEDGE_DATABASE' => $this->knowledgeDatabase,
        'GIT_AUTHOR_NAME' => 'Molly Tests', 'GIT_AUTHOR_EMAIL' => 'tests@example.com',
        'GIT_COMMITTER_NAME' => 'Molly Tests', 'GIT_COMMITTER_EMAIL' => 'tests@example.com',
        'GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'commit.gpgsign', 'GIT_CONFIG_VALUE_0' => 'false',
    ], timeout: 120);
    $shell->run();

    $real = str_replace('\\', '/', realpath($target));
    expect($shell->getExitCode())->toBe(0, $script."\n".$shell->getOutput().$shell->getErrorOutput())
        ->and(json_decode(File::get($this->mollyHome.'/projects.json'), true))->toBe([$real])
        ->and(json_decode(File::get($target.'/.molly/project.json'), true)['path'])->toBe($real);
})->with([
    'outside any repository' => [false],
    'inside a committed repository' => [true],
]);

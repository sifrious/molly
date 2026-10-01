<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Sifrious\Molly\MollyServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/*
 * The application behind orbExecutionFixture(): a SQLite database queue, a fake Ollama that
 * lists the Orbs' models, fake writer and review agents, and real Pest checks. Each task runs
 * in its own Git worktree of this application under ../orbs. The writer records its process,
 * the model it was configured with, and the time, so a test can prove which Orb ran what and
 * that two runs overlapped.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = MOLLY_ORB_TEST_ROOT;
$settings = json_decode(file_get_contents($root.'/runtime.json'), true, flags: JSON_THROW_ON_ERROR);
$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app->useStoragePath($root.'/storage');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->setBasePath($root);
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $root.'/database.sqlite',
    'database.connections.sqlite.busy_timeout' => 10000,
    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
    'queue.default' => 'database',
    'queue.connections.database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 4000, 'after_commit' => false],
    'queue.failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs'],
    'cache.default' => 'array',
    'logging.default' => 'single',
    'logging.channels.single.path' => $root.'/storage/logs/runtime.log',
    'molly.sandbox.allow_unsafe' => true,
    'molly.model' => 'fixture-default-model',
    'molly.parallel_checks' => true,
    'molly.timeout' => 5,
    'molly.test_timeout' => $settings['test_timeout'],
    'molly.worker.php_binary' => PHP_BINARY,
    'molly-complexity.enabled' => true,
    'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
]);
$app->register(AiServiceProvider::class);
$app->register(MollyServiceProvider::class);
Http::fake([
    '127.0.0.1:11434/api/tags' => Http::response(['models' => array_map(fn (string $model): array => ['name' => $model, 'model' => $model, 'digest' => hash('sha256', $model)], $settings['models'])]),
    '127.0.0.1:11434/api/version' => Http::response(['version' => '0.12.3']),
]);
Http::preventStrayRequests();

$waitFor = function (Closure $condition, string $what): void {
    $deadline = microtime(true) + 20;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Orb fixture barrier timed out waiting for '.$what.'.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$lines = fn (string $file): int => is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES)) : 0;

Queue::before(function (JobProcessing $event) use ($root, $settings, $waitFor): void {
    file_put_contents($root.'/reserved-'.getmypid(), $event->job->getJobId());
    if ($settings['duplicate']) {
        $waitFor(fn (): bool => count(glob($root.'/reserved-*')) === 2, 'both workers to reserve a job');
    }
});
Queue::failing(function (JobFailed $event) use ($root): void {
    file_put_contents($root.'/worker-failed-'.getmypid(), $event->exception->getMessage());
});
ChangeWriter::fake(function () use ($root, $settings, $waitFor, $lines): array {
    file_put_contents($root.'/generation-calls', json_encode(['pid' => getmypid(), 'model' => config('molly.model'), 'agent' => config('molly.agent'), 'at' => microtime(true)])."\n", FILE_APPEND | LOCK_EX);
    // Wait until the expected number of writers are inside a model call at the same time.
    $waitFor(fn (): bool => $lines($root.'/generation-calls') >= $settings['barrier'], $settings['barrier'].' writers at once');
    if ($settings['duplicate']) {
        $waitFor(fn (): bool => count(glob($root.'/worker-failed-*')) === 1, 'the duplicate job to fail');
    }
    // Stand in for a slow model request: answer only after the test removes the hold file.
    $deadline = microtime(true) + 30;
    while (is_file($root.'/hold') && microtime(true) < $deadline) {
        usleep(20000);
        clearstatcache(true, $root.'/hold');
    }

    return ['summary' => 'Return true from the flag.', 'files' => [['path' => 'app/Flag.php', 'content' => "<?php\nreturn true;\n"]]];
})->preventStrayPrompts();
TarpitReviewer::fake(function () use ($root): array {
    file_put_contents($root.'/review-calls', json_encode(['pid' => getmypid(), 'model' => config('molly.model')])."\n", FILE_APPEND | LOCK_EX);

    return ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The selected PHP file returns one literal.']), 'findings' => []];
})->preventStrayPrompts();

Artisan::command('fixture:setup', function () use ($root, $settings, $kernel): void {
    $kernel->call('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true, '--force' => true]);
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    foreach ($settings['orbs'] as $name => $model) {
        app(LocalOrbProvider::class)->register($name, 'ollama', $model, $root, dirname($root).'/orbs');
    }
    foreach ($settings['tasks'] as $name => $workspace) {
        app(CreateTask::class)->handle('Return true from the flag.', $workspace, ['app/Flag.php'], 'tests/QueuedFlagTest.php', nickname: $name);
    }
    $this->line('ready');
});

exit($kernel->handle(new ArgvInput, new ConsoleOutput));

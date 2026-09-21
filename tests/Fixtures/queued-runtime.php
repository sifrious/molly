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
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\MollyServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = MOLLY_QUEUE_TEST_ROOT;
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
    'queue.connections.database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 120, 'after_commit' => false],
    'queue.failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs'],
    'cache.default' => 'array',
    'logging.default' => 'single',
    'logging.channels.single.path' => $root.'/storage/logs/runtime.log',
    'molly.sandbox.allow_unsafe' => true,
    'molly.model' => 'queue-fixture-model',
    'molly.parallel_checks' => true,
    'molly.timeout' => 5,
    'molly.test_timeout' => 5,
    'molly-complexity.enabled' => true,
    'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
]);
$app->register(AiServiceProvider::class);
$app->register(MollyServiceProvider::class);
Http::preventStrayRequests();

$waitFor = function (Closure $condition): void {
    $deadline = microtime(true) + 8;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Queue fixture barrier timed out.');
        }
        usleep(10000);
    }
};

Queue::before(function (JobProcessing $event) use ($root, $settings, $waitFor): void {
    file_put_contents($root.'/reserved-'.getmypid(), $event->job->getJobId());
    if ($settings['duplicate']) {
        $waitFor(fn (): bool => count(glob($root.'/reserved-*')) === 2);
    }
});
Queue::failing(function (JobFailed $event) use ($root): void {
    file_put_contents($root.'/worker-failed-'.getmypid(), $event->exception->getMessage());
});
ChangeWriter::fake(function () use ($root, $settings, $waitFor): array {
    file_put_contents($root.'/generation-calls', getmypid()."\n", FILE_APPEND | LOCK_EX);
    if ($settings['duplicate']) {
        $waitFor(fn (): bool => count(glob($root.'/worker-failed-*')) === 1);
    }

    return ['summary' => 'Return true from the flag.', 'files' => [['path' => 'app/Flag.php', 'content' => "<?php\nreturn true;\n"]]];
})->preventStrayPrompts();
TarpitReviewer::fake(function () use ($root): array {
    file_put_contents($root.'/review-calls', getmypid()."\n", FILE_APPEND | LOCK_EX);

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
    $task = app(CreateTask::class)->handle('Return true from the flag.', $root, ['app/Flag.php'], 'tests/QueuedFlagTest.php');
    Queue::push(new StartSavedTask($task->id));
    if ($settings['duplicate']) {
        Queue::push(new StartSavedTask($task->id));
    }
    if ($settings['stop']) {
        app(StopTask::class)->handle($task->id);
    }
    $this->line($task->id);
});

exit($kernel->handle(new ArgvInput, new ConsoleOutput));

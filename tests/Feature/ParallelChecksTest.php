<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\EvaluateChanges;
use Sifrious\Molly\Workspace;
use Symfony\Component\Process\Exception\ProcessSignaledException;

function parallelCheckWorkspace(string $mode = 'pass'): string
{
    $directory = sys_get_temp_dir().'/molly-parallel-'.bin2hex(random_bytes(8));
    mkdir($directory.'/vendor/bin', 0700, true);
    file_put_contents($directory.'/mode', $mode);
    $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
    file_put_contents($directory.'/artisan', '<?php require '.$autoload.';'.<<<'PHPCHILD'
$app = Orchestra\Testbench\Foundation\Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app->register(Laravel\Ai\AiServiceProvider::class);
$kernel->registerCommand(new Sifrious\Molly\Console\MollyCheckCommand);
Illuminate\Support\Facades\Http::preventStrayRequests();
Sifrious\Molly\Agents\TarpitReviewer::fake(function ($prompt) {
    $directory = getcwd();
    file_put_contents($directory.'/review-start', json_encode(['pid' => getmypid(), 'prompt' => json_decode($prompt, true), 'model' => config('molly.model')]));
    $deadline = microtime(true) + 5;
    while (! is_file($directory.'/verification-start') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/verification-start')) {
        throw new RuntimeException('Verification did not overlap review.');
    }
    $mode = file_get_contents($directory.'/mode');
    if (in_array($mode, ['cancel', 'stubborn_cancel', 'review_timeout'], true)) {
        sleep(30);
    }
    if ($mode === 'review_fail') {
        throw new RuntimeException('Model request failed.');
    }
    if ($mode === 'wrapper_crash') {
        $pid = (int) file_get_contents($directory.'/verification-start');
        $deadline = microtime(true) + 5;
        while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (@posix_kill($pid, 0)) {
            throw new RuntimeException('Pest remained alive while review was running.');
        }
    }
    $checks = array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The supplied change uses the existing function.']);
    return ['checks' => $checks, 'findings' => []];
})->preventStrayPrompts();
exit($kernel->handle(new Symfony\Component\Console\Input\ArgvInput, new Symfony\Component\Console\Output\ConsoleOutput));
PHPCHILD);
    file_put_contents($directory.'/vendor/bin/pest', <<<'PHPPEST'
<?php
$directory = getcwd();
if (file_get_contents($directory.'/mode') === 'stubborn_cancel') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
}
file_put_contents($directory.'/verification-start', (string) getmypid());
$deadline = microtime(true) + 5;
while (! is_file($directory.'/review-start') && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($directory.'/review-start')) {
    fwrite(STDERR, 'Review did not overlap verification.');
    exit(1);
}
$mode = file_get_contents($directory.'/mode');
if ($mode === 'wrapper_crash') {
    posix_kill(posix_getppid(), SIGKILL);
    sleep(30);
}
if (in_array($mode, ['cancel', 'stubborn_cancel', 'test_timeout'], true)) {
    sleep(30);
}
if ($mode === 'missing_junit') {
    exit(0);
}
$junit = $argv[array_search('--log-junit', $argv, true) + 1];
file_put_contents($junit, '<testsuite tests="1" assertions="2"><testcase name="proof" assertions="2"/></testsuite>');
PHPPEST);

    return $directory;
}

function runParallelChecks(string $directory, ?Closure $stop = null, ?Closure $recordBranches = null): array
{
    $base = app()->basePath();
    app()->setBasePath($directory);
    try {
        return (new Workspace($directory))->exclusively(fn (string $lease): array => app(EvaluateChanges::class)->handle('Use the existing function.', $directory, ['src/Example.php' => 'old'], ['src/Example.php' => 'new'], 'tests/ExampleTest.php', $directory.'/evidence', $stop, $lease, $recordBranches));
    } finally {
        app()->setBasePath($base);
    }
}

it('runs verification and review concurrently and joins immutable evidence', function (): void {
    $directory = parallelCheckWorkspace();
    config(['molly.model' => 'snapshot-model', 'molly.timeout' => 15]);
    try {
        $progress = [];
        $result = runParallelChecks($directory, recordBranches: function (array $branches) use (&$progress): void {
            $progress[] = $branches;
        });

        expect($progress)->toHaveCount(3);
        expect($progress[array_key_last($progress)])->toBe($result['branches']);
        expect(array_column($progress[0], 'status'))->toBe(['running', 'running']);
        expect(array_column($progress[array_key_last($progress)], 'status'))->toBe(['passed', 'passed']);
        expect($result['mode'])->toBe('parallel');
        expect($result['verification']['status'])->toBe('passed', json_encode($result));
        expect($result['verification']['tests'])->toBe(1);
        expect($result['review']['checks'])->toHaveCount(7);
        expect(array_column($result['branches'], 'status'))->toBe(['passed', 'passed']);
        $review = json_decode(file_get_contents($directory.'/review-start'), true);
        expect($review['model'])->toBe('snapshot-model');
        expect($review['prompt']['before'])->toBe(['src/Example.php' => 'old']);
        expect($review['prompt']['after'])->toBe(['src/Example.php' => 'new']);
        expect($review['pid'])->not->toBe((int) file_get_contents($directory.'/verification-start'));
        expect(glob($directory.'/evidence/*-input.json'))->toBe([]);
        foreach ($result['branches'] as $branch) {
            expect($branch['finished_at'])->not->toBeNull();
            expect(is_file($branch['result_ref']))->toBeTrue();
            expect(fileperms($branch['result_ref']) & 0777)->toBe(0600);
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('preserves the other branch evidence when one check fails', function (string $mode, string $failed, string $passed): void {
    $directory = parallelCheckWorkspace($mode);
    try {
        $result = runParallelChecks($directory);

        $branches = array_column($result['branches'], null, 'kind');
        expect($branches[$failed]['status'])->toBe('failed', json_encode($result));
        expect($branches[$passed]['status'])->toBe('passed');
        expect($branches[$failed]['failure_classification'])->not->toBeNull();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    ['missing_junit', 'verification', 'review'],
    ['review_fail', 'review', 'verification'],
]);

it('cancels both running checks and stops the Pest process', function (string $mode): void {
    $directory = parallelCheckWorkspace($mode);
    try {
        $result = runParallelChecks($directory, fn (): bool => is_file($directory.'/review-start') && is_file($directory.'/verification-start'));

        expect(array_column($result['branches'], 'status'))->toBe(['cancelled', 'cancelled']);
        expect($result['verification']['status'])->toBe('failed');
        expect($result['review']['checks'])->toBe([]);
        if (function_exists('posix_kill')) {
            $pid = (int) file_get_contents($directory.'/verification-start');
            $deadline = microtime(true) + 2;
            while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(@posix_kill($pid, 0))->toBeFalse();
        }
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with(['cancel', 'stubborn_cancel']);

it('rejects missing and mismatched branch results', function (string $body): void {
    $directory = parallelCheckWorkspace();
    file_put_contents($directory.'/artisan', '<?php '.$body);
    try {
        $result = runParallelChecks($directory);

        expect(array_column($result['branches'], 'status'))->toBe(['failed', 'failed']);
        expect(array_column($result['branches'], 'failure_classification'))->toBe(['branch_result_invalid', 'branch_result_invalid']);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    'missing' => 'exit(0);',
    'invalid JSON' => 'file_put_contents($argv[3], "broken");',
    'wrong branch' => 'file_put_contents($argv[3], json_encode(["branch_id" => "other", "attempt_id" => "other", "status" => "passed", "result" => []]));',
]);

it('reports timeouts without discarding the completed branch', function (string $mode, string $timedOut): void {
    $directory = parallelCheckWorkspace($mode);
    config(['molly.timeout' => 1, 'molly.test_timeout' => 1]);
    try {
        $result = runParallelChecks($directory);

        $branches = array_column($result['branches'], null, 'kind');
        expect($branches[$timedOut]['status'])->toBe('timed_out', json_encode($result));
        expect($branches[$timedOut === 'review' ? 'verification' : 'review']['status'])->toBe('passed');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([['test_timeout', 'verification'], ['review_timeout', 'review']]);

it('stops a Pest descendant after the verification wrapper crashes', function (): void {
    $directory = parallelCheckWorkspace('wrapper_crash');
    try {
        $result = runParallelChecks($directory);

        expect($result['branches'][0]['status'])->toBe('failed');
        expect($result['branches'][1]['status'])->toBe('passed', json_encode($result));
        $pid = (int) file_get_contents($directory.'/verification-start');
        $deadline = microtime(true) + 2;
        while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(@posix_kill($pid, 0))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('rejects a delayed check from an earlier workspace lease', function (): void {
    $directory = parallelCheckWorkspace();
    try {
        $workspace = new Workspace($directory);
        $oldLease = $workspace->exclusively(fn (string $lease): string => $lease);
        $workspace->exclusively(fn (): null => null);

        expect(fn () => $workspace->duringCheck($oldLease, fn (): bool => true))
            ->toThrow(RuntimeException::class, 'WORKSPACE_LEASE_EXPIRED');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('keeps the workspace busy after the parent exits while a check holds its lease', function (): void {
    $directory = parallelCheckWorkspace();
    $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
    $bootstrap = '<?php require '.$autoload.'; $app = Orchestra\\Testbench\\Foundation\\Application::create(options: ["extra" => ["dont-discover" => ["*"]]]); $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();';
    file_put_contents($directory.'/check.php', $bootstrap.<<<'CHILD'
(new Sifrious\Molly\Workspace(__DIR__))->duringCheck($argv[1], function () {
    file_put_contents(__DIR__.'/check-ready', (string) getmypid());
    $deadline = microtime(true) + 5;
    while (! is_file(__DIR__.'/release-check') && microtime(true) < $deadline) {
        usleep(10000);
    }
});
CHILD);
    file_put_contents($directory.'/parent.php', $bootstrap.<<<'PARENT'
(new Sifrious\Molly\Workspace(__DIR__))->exclusively(function (string $lease) {
    $check = Illuminate\Support\Facades\Process::start([PHP_BINARY, __DIR__.'/check.php', $lease]);
    $deadline = microtime(true) + 3;
    while (! is_file(__DIR__.'/check-ready') && microtime(true) < $deadline) {
        usleep(10000);
    }
    posix_kill(getmypid(), SIGKILL);
});
PARENT);
    try {
        expect(fn () => Process::timeout(5)->run([PHP_BINARY, $directory.'/parent.php']))
            ->toThrow(ProcessSignaledException::class);

        expect(is_file($directory.'/check-ready'))->toBeTrue();
        expect(fn () => (new Workspace($directory))->exclusively(fn (): bool => true))
            ->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');
        file_put_contents($directory.'/release-check', 'release');
        $pid = (int) file_get_contents($directory.'/check-ready');
        $deadline = microtime(true) + 3;
        while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect((new Workspace($directory))->exclusively(fn (): bool => true))->toBeTrue();
    } finally {
        file_put_contents($directory.'/release-check', 'release');
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('refuses a symlinked workspace lease before starting checks', function (): void {
    $directory = parallelCheckWorkspace();
    mkdir($directory.'/.molly', 0700);
    file_put_contents($directory.'/other', 'unchanged');
    symlink($directory.'/other', $directory.'/.molly/checks.lease');
    try {
        expect(fn () => (new Workspace($directory))->exclusively(fn (): bool => true))
            ->toThrow(RuntimeException::class, 'WORKSPACE_LOCK_INVALID');
        expect(file_get_contents($directory.'/other'))->toBe('unchanged');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('stops active child actions when recording branch progress fails', function (): void {
    $directory = parallelCheckWorkspace('stubborn_cancel');
    $record = function () use ($directory): never {
        $deadline = microtime(true) + 5;
        while ((! is_file($directory.'/review-start') || ! is_file($directory.'/verification-start')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        throw new RuntimeException('Cannot save branch progress.');
    };
    try {
        expect(fn () => runParallelChecks($directory, recordBranches: $record))
            ->toThrow(RuntimeException::class, 'Cannot save branch progress.');

        $review = json_decode(file_get_contents($directory.'/review-start'), true);
        $pids = [$review['pid'], (int) file_get_contents($directory.'/verification-start')];
        foreach ($pids as $pid) {
            $deadline = microtime(true) + 2;
            while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(@posix_kill($pid, 0))->toBeFalse();
        }
        expect(glob($directory.'/evidence/*-input.json'))->toBe([]);
        expect((new Workspace($directory))->exclusively(fn (): bool => true))->toBeTrue();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('stops descendants if a child creates its process group during cancellation', function (): void {
    $directory = parallelCheckWorkspace();
    file_put_contents($directory.'/artisan', <<<'CHILD'
<?php
$input = json_decode(file_get_contents($argv[2]), true);
$kind = $input['kind'];
$output = $argv[3];
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use ($kind, $output): never {
    posix_setsid();
    file_put_contents($output.'.group', (string) getmypid());
    $pid = pcntl_fork();
    if ($pid === 0) {
        pcntl_signal(SIGTERM, SIG_IGN);
        file_put_contents(getcwd().'/'.$kind.'-descendant', (string) getmypid());
        sleep(30);
        exit(0);
    }
    $deadline = microtime(true) + 1;
    while (! is_file(getcwd().'/'.$kind.'-descendant') && microtime(true) < $deadline) {
        usleep(1000);
    }
    exit(0);
});
file_put_contents(getcwd().'/'.$kind.'-ready', 'ready');
while (true) {
    usleep(10000);
}
CHILD);
    $record = function () use ($directory): never {
        $deadline = microtime(true) + 5;
        while ((! is_file($directory.'/review-ready') || ! is_file($directory.'/verification-ready')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        throw new RuntimeException('Cannot save branch progress.');
    };
    try {
        expect(fn () => runParallelChecks($directory, recordBranches: $record))
            ->toThrow(RuntimeException::class, 'Cannot save branch progress.');

        foreach (['verification', 'review'] as $kind) {
            $pid = (int) file_get_contents($directory.'/'.$kind.'-descendant');
            $deadline = microtime(true) + 2;
            while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(@posix_kill($pid, 0))->toBeFalse();
        }
    } finally {
        foreach (glob($directory.'/*-descendant') as $path) {
            @posix_kill((int) file_get_contents($path), 9);
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});

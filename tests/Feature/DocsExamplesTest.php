<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Models\Task;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->workspace = laravelShapedWorkspace();
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('keeps the getting started ReadyTest identical to the tested fixture', function () {
    $documented = File::get(dirname(__DIR__, 2).'/docs/getting-started.md');

    expect($documented)->toContain(trim(File::get(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php')));
});

it('runs the getting started ReadyTest red for missing behavior, then saves the documented task', function () {
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $this->workspace.'/tests/Feature/ReadyTest.php');

    $verification = app(VerifyChanges::class)->handle($this->workspace, 'tests/Feature/ReadyTest.php', $this->workspace.'/evidence');
    $classification = app(RecordRedBaseline::class)->classify($verification, $this->workspace, 'tests/Feature/ReadyTest.php');

    expect($verification['reason'])->toBe('tests_failed', $verification['output'])
        ->and($verification['output'])->toContain('404')
        ->and($classification['classification'])->toBe('missing_behavior');

    [$exit, $result] = mollyJson('molly:create', [
        'prompt' => 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.',
        '--workspace' => $this->workspace,
        '--name' => 'ready-check',
        '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'],
    ]);

    expect($exit)->toBe(0)
        ->and(Task::findOrFail($result['id'])->only(['nickname', 'paths', 'test_path', 'allow_test_edits']))->toBe([
            'nickname' => 'ready-check',
            'paths' => ['routes/web.php'],
            'test_path' => 'tests/Feature/ReadyTest.php',
            'allow_test_edits' => false,
        ]);
});

it('saves the two tasks at once example in a second checkout with its own lock', function () {
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $this->workspace.'/tests/Feature/ReadyTest.php');
    $second = $this->workspace.'-ready';
    // docs/tutorials.md: git worktree add -b ready ../app-ready, then mkdir -p and copy the untracked test in.
    (new Process(['git', 'worktree', 'add', '--quiet', '-b', 'ready', $second], $this->workspace))->mustRun();
    File::ensureDirectoryExists($second.'/tests/Feature');
    File::copy($this->workspace.'/tests/Feature/ReadyTest.php', $second.'/tests/Feature/ReadyTest.php');

    try {
        $create = fn (string $workspace, string $name): array => mollyJson('molly:create', [
            'prompt' => 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.',
            '--workspace' => $workspace,
            '--name' => $name,
            '--test' => 'tests/Feature/ReadyTest.php',
            '--file' => ['routes/web.php'],
        ]);
        [$firstExit, $first] = $create($this->workspace, 'ready-check');
        [$secondExit, $saved] = $create($second, 'ready');
        [, $tasks] = mollyJson('molly:tasks', []);
        $verification = app(VerifyChanges::class)->handle($second, 'tests/Feature/ReadyTest.php', $second.'/evidence');

        expect([$firstExit, $secondExit])->toBe([0, 0])
            ->and(Task::findOrFail($saved['id'])->workspace)->toBe(realpath($second))
            ->and(Task::findOrFail($first['id'])->workspace)->toBe(realpath($this->workspace))
            ->and(array_column($tasks['tasks'], 'nickname'))->toContain('ready', 'ready-check')
            ->and($verification['reason'])->toBe('tests_failed', $verification['output'])
            ->and($verification['output'])->toContain('404');
    } finally {
        File::deleteDirectory($second);
    }
});

it('follows the two local Orbs tutorial from worktrees to one queued task per Orb and a busy refusal', function () {
    $tutorial = File::get(dirname(__DIR__, 2).'/docs/execution-targets.md');
    $busy = 'ORB_BUSY: Orb big is working on task orb-greeting. Each Orb takes one task at a time. Wait for that task to finish, or choose another Orb.';
    expect($tutorial)->toContain('php artisan molly:queue demo-greeting --orb=big')->toContain($busy);
    $commands = tutorialCommands($tutorial, '## Run tasks on two local Orbs');
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
    Http::fake(['127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'gpt-oss:120b-code'], ['name' => 'gpt-oss:20b']]]), '127.0.0.1:11434/api/version' => Http::response(['version' => '0.12.3'])]);
    Queue::fake([StartSavedTask::class]);
    // The tutorial runs from the app root with ../orbs beside it, so the app moves into its own parent.
    $parent = $this->workspace.'-tutorial';
    File::ensureDirectoryExists($parent);
    rename($this->workspace, $parent.'/app');
    $this->workspace = $parent.'/app';
    $cwd = getcwd();

    try {
        // Getting started: molly:demo and the ready test, committed so each worktree has them.
        expect(Artisan::call('molly:demo', ['--workspace' => $this->workspace, '--json' => true]))->toBe(0);
        File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $this->workspace.'/tests/Feature/ReadyTest.php');
        (new Process(['git', 'add', 'app/Greeting.php', 'tests/Feature/GreetingTest.php', 'tests/Feature/ReadyTest.php'], $this->workspace))->mustRun();
        (new Process(['git', '-c', 'user.name=Molly Tests', '-c', 'user.email=tests@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Add the greeting and ready tests'], $this->workspace))->mustRun();

        // The documented commands, as written, from the app root, up to the busy refusal.
        // Composer, the .env copies, and the workers are left out; the queue is faked.
        chdir($this->workspace);
        $ran = [];
        foreach ($commands as $command) {
            if (str_starts_with($command, 'mkdir ') || str_starts_with($command, 'git worktree add ')) {
                Process::fromShellCommandline($command, $this->workspace)->mustRun();
                $ran[] = [$command, 0, null];
            } elseif (preg_match('/\Aphp artisan (molly:(?:create|orb-register|queue) .+|molly:orbs)\z/', $command, $artisan) === 1) {
                if ($command === 'php artisan molly:queue demo-greeting --orb=big') {
                    // "While the models work": the worker has picked up orb-greeting.
                    Task::where('nickname', 'orb-greeting')->update(['status' => 'running']);
                }
                // Testbench's application root is not the workspace, so each Orb names its repository.
                $line = str_starts_with($artisan[1], 'molly:orb-register ') ? $artisan[1].' --repository=.' : $artisan[1];
                $exit = Artisan::call($line.' --json');
                $ran[] = [$command, $exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
                if (str_starts_with($command, 'php artisan molly:queue demo-greeting')) {
                    break;
                }
            }
        }
        $output = fn (string $command): array => collect($ran)->firstWhere(0, $command)[2];

        $greeting = "php artisan molly:create 'Return Hello from the greeting helper.' --workspace=../orbs/greeting --name=orb-greeting --test=tests/Feature/GreetingTest.php --file=app/Greeting.php";
        $ready = "php artisan molly:create 'Add GET /ready returning exactly {\"ready\":true}. Preserve existing routes.' --workspace=../orbs/ready --name=orb-ready --test=tests/Feature/ReadyTest.php --file=routes/web.php";
        $big = 'php artisan molly:orb-register big --model=gpt-oss:120b-code --worktree-root=../orbs';
        $small = 'php artisan molly:orb-register small --model=gpt-oss:20b --worktree-root=../orbs';
        $refusal = array_pop($ran);
        expect(array_column($ran, 0))->toBe([
            'mkdir ../orbs',
            'git worktree add -b orb-greeting ../orbs/greeting',
            'git worktree add -b orb-ready ../orbs/ready',
            $greeting, $ready, $big, $small,
            'php artisan molly:orbs',
            'php artisan molly:queue orb-greeting --orb=big',
            'php artisan molly:queue orb-ready --orb-model=gpt-oss:20b',
            'php artisan molly:orbs',
        ])
            ->and(collect($ran)->filter(fn (array $step): bool => $step[1] !== 0)->map(fn (array $step): string => $step[0].': '.($step[2]['error'] ?? ''))->all())->toBe([])
            ->and(Task::findOrFail($output($greeting)['id'])->workspace)->toBe(realpath($parent.'/orbs/greeting'))
            ->and(Task::findOrFail($output($ready)['id'])->workspace)->toBe(realpath($parent.'/orbs/ready'))
            ->and($output($big)['orb']['worktree_root'])->toBe(realpath($parent.'/orbs'))
            ->and(array_column($output('php artisan molly:orbs')['orbs'], 'health', 'name'))->toBe(['big' => 'healthy', 'small' => 'healthy'])
            ->and($output('php artisan molly:queue orb-greeting --orb=big')['orb']['id'])->toBe($output($big)['orb']['id'])
            ->and($output('php artisan molly:queue orb-ready --orb-model=gpt-oss:20b')['orb']['id'])->toBe($output($small)['orb']['id'])
            ->and(array_column($ran[10][2]['orbs'], 'availability', 'name'))->toBe(['big' => 'busy', 'small' => 'busy'])
            ->and($refusal[0])->toBe('php artisan molly:queue demo-greeting --orb=big')
            ->and($refusal[1])->toBe(1)
            ->and($refusal[2]['error'])->toBe($busy);
        Queue::assertPushed(StartSavedTask::class, 2);
    } finally {
        chdir($cwd);
        File::deleteDirectory($parent);
    }
});

/**
 * The shell commands in the fenced bash blocks of one tutorial section, in order,
 * with backslash line continuations joined into one line.
 *
 * @return list<string>
 */
function tutorialCommands(string $markdown, string $heading): array
{
    $start = strpos($markdown, $heading."\n");
    expect($start)->not->toBeFalse();
    $section = substr($markdown, $start + strlen($heading));
    $end = preg_match('/^## /m', $section, $next, PREG_OFFSET_CAPTURE) === 1 ? $next[0][1] : strlen($section);
    preg_match_all('/```bash\n(.*?)```/s', substr($section, 0, $end), $blocks);
    $lines = preg_split('/\n/', preg_replace('/\\\\\n\s*/', '', implode("\n", $blocks[1])));

    return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
}

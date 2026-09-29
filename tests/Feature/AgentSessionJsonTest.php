<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Tests\Support\AgentOutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/*
 * D-RC10-2. New Laravel 13 apps require laravel/pao for development. When Artisan runs under an
 * AI agent, pao replaces Laravel's OutputStyle with one that collapses runs of spaces, drops
 * blank lines, and shortens "..." to "..". RC10 printed --json through OutputStyle, so in an
 * agent session `molly:preflight --json > snap.json` wrote a snapshot that
 * `molly:preflight --snapshot=snap.json` refused with SNAPSHOT_INVALID. Each test here binds
 * AgentOutputStyle, which rewrites output the way pao does, and sets the agent variables.
 */

const MOLLY_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;

beforeEach(function () {
    $this->agentVariables = ['CLAUDECODE' => getenv('CLAUDECODE'), 'AI_AGENT' => getenv('AI_AGENT')];
    putenv('CLAUDECODE=1');
    putenv('AI_AGENT=claude-code');
    app()->bind(OutputStyle::class, AgentOutputStyle::class);
    $this->home = sys_get_temp_dir().'/molly-agent-json-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->home);
});

afterEach(function () {
    foreach ($this->agentVariables as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
    }
    File::deleteDirectory($this->home);
});

/** Print text the way RC10 printed --json, through the agent session's OutputStyle. */
function throughAgentOutputStyle(string $text): string
{
    $buffer = new BufferedOutput;
    (new AgentOutputStyle(new ArrayInput([]), $buffer))->writeln($text);

    return $buffer->fetch();
}

it('reproduces the RC10 failure: a snapshot printed through the agent OutputStyle fails its own digest', function () {
    $fixture = replayHardwareFixture('m3-ultra-96gb');
    Artisan::call('molly:preflight', ['--destination' => $fixture['destination'], '--json' => true]);
    $rewritten = throughAgentOutputStyle(rtrim(Artisan::output(), "\n"));
    File::put($saved = $this->home.'/rewritten.json', $rewritten);

    $exit = Artisan::call('molly:preflight', ['--snapshot' => $saved, '--json' => true]);

    expect($rewritten)->toContain('Pages free: 261437.')
        ->and($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true)['message'])->toBe('SNAPSHOT_INVALID: snapshot_sha256 does not match the facts in the snapshot.');
});

it('prints molly:preflight --json byte for byte in an agent session, so --snapshot reads the file back', function () {
    $fixture = replayHardwareFixture('m3-ultra-96gb');

    $exit = Artisan::call('molly:preflight', ['--destination' => $fixture['destination'], '--json' => true]);
    $printed = Artisan::output();
    $document = json_decode($printed, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($printed)->toBe(json_encode($document, MOLLY_JSON_FLAGS | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)."\n")
        ->and(hardwareFact($document, 'memory.vm_pages')['raw'])->toContain('Pages free:                                        261437.');

    File::put($saved = $this->home.'/snap.json', $printed);
    $exit = Artisan::call('molly:preflight', ['--snapshot' => $saved, '--json' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toBe($printed);
});

it('round-trips molly:preflight --json through a file in its own process with the agent variables set', function () {
    $fixture = replayHardwareFixture('m3-ultra-96gb');
    Artisan::call('molly:preflight', ['--destination' => $fixture['destination'], '--json' => true]);
    File::put($measured = $this->home.'/measured.json', Artisan::output());
    $artisan = function (string $snapshot): Process {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/agent-artisan.php', 'molly:preflight', '--snapshot='.$snapshot, '--json'], dirname(__DIR__, 2), [
            'CLAUDECODE' => '1', 'AI_AGENT' => 'claude-code', 'MOLLY_HOME' => $this->home.'/molly', 'MOLLY_LOCAL_MODEL' => '', 'APP_ENV' => 'local',
        ], timeout: 60);
        $process->run();

        return $process;
    };

    $first = $artisan($measured);
    File::put($saved = $this->home.'/snap.json', $first->getOutput());
    $second = $artisan($saved);

    expect($first->getExitCode())->toBe(0, $first->getErrorOutput())
        ->and($first->getOutput())->toContain('Pages free:                                        261437.')
        ->and($second->getExitCode())->toBe(0, $second->getErrorOutput())
        ->and($second->getOutput())->toBe($first->getOutput());
});

it('prints success and failure documents byte for byte when the text holds tags, runs of spaces, and ellipses', function () {
    $workspace = $this->home.'/app';
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($workspace);
    commitGitWorkspace($workspace);
    $prompt = 'Print <info>Saved</info> and <fg=red>two  spaces</> in the table, then wait... for the queue.';
    $create = fn (string $test): array => [
        Artisan::call('molly:create', ['prompt' => $prompt, '--workspace' => $workspace, '--test' => $test, '--file' => ['app/Greeting.php'], '--json' => true]),
        Artisan::output(),
    ];

    [$exit, $created] = $create('tests/GreetingTest.php');
    $document = json_decode($created, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($document['task']['prompt'])->toBe($prompt)
        ->and($created)->toBe(json_encode($document, MOLLY_JSON_FLAGS)."\n");

    [$exit, $refused] = $create('app/Greeting.php');
    $error = json_decode($refused, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($error['rerun'])->toContain(escapeshellarg($prompt))
        ->and($refused)->toBe(json_encode($error, MOLLY_JSON_FLAGS)."\n");
});

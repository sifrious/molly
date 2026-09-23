<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Agents\ChangeWriter;

function ampEvents(array $events): string
{
    return implode("\n", array_map(fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR), $events));
}

function ampSuccess(array $data): string
{
    return ampEvents([
        ['type' => 'system', 'subtype' => 'init', 'tools' => []],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => json_encode($data)],
    ]);
}

it('uses isolated tool-free Amp settings and validates the returned proposal', function () {
    config(['molly.agent' => 'amp', 'molly.model' => null]);
    $directory = null;
    Process::fake(function (PendingProcess $process) use (&$directory) {
        $directory = $process->path;
        $settings = json_decode(file_get_contents($directory.'/settings.json'), true);
        expect($settings['amp.tools.disable'])->toBe(['*'])
            ->and($settings['amp.tools.enable'])->toBe(['__molly_no_tools__'])
            ->and($settings['amp.mcpServers'])->toBe([])
            ->and($settings['amp.mcpPermissions'])->toHaveCount(2)
            ->and(fileperms($directory.'/settings.json') & 0777)->toBe(0600)
            ->and(fileperms($directory) & 0777)->toBe(0700)
            ->and($process->command)->toContain('--no-ide', '--no-remote-control-terminal', '--stream-json', '-x')
            ->and($process->input)->toContain('allowed_files', 'required_test', 'previous_attempt');

        return Process::result(output: ampSuccess(['summary' => 'Fix greeting.', 'files' => [['path' => 'app/Hello.php', 'content' => '<?php']]]));
    });
    $result = app(GenerateChanges::class)->handle('Fix greeting.', ['app/Hello.php' => null], 'tests/Hello.php', ['failure' => 'wrong greeting']);
    expect($result['files'][0]['path'])->toBe('app/Hello.php')->and(is_dir($directory))->toBeFalse();
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
    Http::assertNothingSent();
});

it('rejects Amp terminal failures disconnects tool calls and invalid JSON', function (string $output, string $error) {
    Process::fake(fn () => Process::result(output: $output));
    expect(fn () => app(AmpResponse::class)->prompt(new ChangeWriter, '{}'))->toThrow(RuntimeException::class, $error);
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
})->with([
    'no result' => [ampEvents([['type' => 'system', 'subtype' => 'init', 'tools' => []]]), 'AMP_INCOMPLETE'],
    'failed result' => [ampEvents([['type' => 'system', 'subtype' => 'init', 'tools' => []], ['type' => 'result', 'subtype' => 'error', 'is_error' => true]]), 'AMP_INCOMPLETE'],
    'cancelled result' => [ampEvents([['type' => 'system', 'subtype' => 'init', 'tools' => []], ['type' => 'result', 'subtype' => 'cancelled', 'is_error' => false]]), 'AMP_INCOMPLETE'],
    'missing init' => [ampEvents([['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => '{}']]), 'AMP_INCOMPLETE'],
    'tools enabled' => [ampEvents([['type' => 'system', 'subtype' => 'init', 'tools' => ['shell_command']]]), 'AMP_TOOLS_ENABLED'],
    'tool call' => [ampEvents([['type' => 'system', 'subtype' => 'init', 'tools' => []], ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'shell_command']]]]]), 'AMP_TOOLS_ENABLED'],
    'bad stream' => ['not json', 'AMP_OUTPUT_INVALID'],
    'bad final json' => [ampEvents([['type' => 'system', 'subtype' => 'init', 'tools' => []], ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'not json']]), 'AMP_OUTPUT_INVALID'],
    'extra result' => [ampSuccess(['ok' => true])."\n".ampSuccess(['ok' => false]), 'AMP_OUTPUT_INVALID'],
]);

it('requires a successful process exit and removes temporary settings on failure', function () {
    $directory = null;
    Process::fake(function (PendingProcess $process) use (&$directory) {
        $directory = $process->path;

        return Process::result(output: ampSuccess(['ok' => true]), exitCode: 1);
    });
    expect(fn () => app(AmpResponse::class)->prompt(new ChangeWriter, '{}'))->toThrow(RuntimeException::class, 'AMP_FAILED');
    expect(is_dir($directory))->toBeFalse();
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
});

it('uses assistant JSON only after an explicit successful terminal result', function () {
    Process::fake(fn () => Process::result(output: ampEvents([
        ['type' => 'system', 'subtype' => 'init', 'tools' => []],
        ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => '{"summary":"Review"}']]]],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => false],
    ])));
    expect(app(AmpResponse::class)->prompt(new ChangeWriter, '{}'))->toBe(['summary' => 'Review']);
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
});

it('keeps generation path validation after Amp succeeds', function () {
    config(['molly.agent' => 'amp']);
    Process::fake(fn () => Process::result(output: ampSuccess(['summary' => 'Escape.', 'files' => [['path' => '../outside.php', 'content' => '<?php']]])));
    expect(fn () => app(GenerateChanges::class)->handle('Fix.', ['tests/Hello.php' => null], 'tests/Hello.php'))->toThrow(RuntimeException::class, 'GENERATION_INVALID');
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
});

it('keeps all seven review checks required after Amp succeeds', function () {
    config(['molly.agent' => 'amp']);
    Process::fake(fn () => Process::result(output: ampSuccess(['checks' => [], 'findings' => []])));
    expect(fn () => app(ReviewChanges::class)->handle('Review.', [], ['app/Hello.php' => '<?php']))->toThrow(RuntimeException::class, 'REVIEW_INVALID');
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
});

it('checks Amp account health without disclosing command output or contacting Ollama', function (int $exitCode, string $code) {
    config(['molly.agent' => 'amp', 'molly.model' => null]);
    Process::fake(fn () => Process::result(output: 'sensitive account details', exitCode: $exitCode));
    $result = app(CheckEnvironment::class)->handle(__DIR__.'/../..');
    expect(array_column($result['checks'], 'code'))->toContain($code)
        ->and(json_encode($result))->not->toContain('sensitive account details', 'ollama');
    Process::assertRan(fn ($process) => $process->command === ['amp', 'usage']);
    Http::assertNothingSent();
})->with([[0, 'amp_ready'], [1, 'amp_unavailable']]);

it('reports process exceptions without leaking output and cleans up settings', function () {
    $directory = null;
    Process::fake(function (PendingProcess $process) use (&$directory) {
        $directory = $process->path;
        throw new RuntimeException('sensitive provider detail');
    });
    expect(fn () => app(AmpResponse::class)->prompt(new ChangeWriter, '{}'))
        ->toThrow(RuntimeException::class, 'AMP_FAILED: Amp could not return a complete response before the deadline.');
    expect(is_dir($directory))->toBeFalse();
});

it('rejects an unbounded Amp timeout before starting a process', function (mixed $timeout) {
    config(['molly.timeout' => $timeout]);
    Process::fake();
    expect(fn () => app(AmpResponse::class)->prompt(new ChangeWriter, '{}'))->toThrow(RuntimeException::class, 'AMP_TIMEOUT_INVALID');
    Process::assertNothingRan();
})->with([0, -1, 3601, '180']);

it('accepts complete review evidence from Amp through the existing validator', function () {
    config(['molly.agent' => 'amp']);
    $checks = array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the supplied function.']);
    Process::fake(fn () => Process::result(output: ampSuccess(['checks' => $checks, 'findings' => []])));
    $review = app(ReviewChanges::class)->handle('Review.', [], ['app/Hello.php' => '<?php']);
    expect($review)->toBe(['checks' => $checks, 'findings' => []]);
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'amp');
});

it('records Amp provenance without claiming an Ollama model on a blocked run', function () {
    config(['molly.agent' => 'amp', 'molly.model' => 'unselected-ollama-model', 'molly-complexity.enabled' => false]);
    Process::fake(fn () => Process::result(output: '', exitCode: 1));
    $directory = sys_get_temp_dir().'/molly-amp-provenance-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        mkdir($directory.'/app', 0700, true);
        File::put($directory.'/app/Hello.php', '<?php');
        writeProtectedTest($directory, 'tests/Hello.php');
        $run = app(RunTask::class)->handle('Return Hello.', $directory, ['app/Hello.php'], 'tests/Hello.php');
        expect($run->status)->toBe('failed')->and($run->report['provider'])->toBe('amp')
            ->and($run->report['model'])->toBeNull()->and($run->report['error'])->toContain('CLEVER_UNAVAILABLE');
        Process::assertNothingRan();
    } finally {
        File::deleteDirectory($directory);
        if (isset($run)) {
            File::deleteDirectory(storage_path('molly/'.$run->id));
        }
    }
});

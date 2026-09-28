<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    // Pest derives a namespace from the test path, and some system temp paths contain segments PHP rejects.
    $this->workspace = '/tmp/molly-story-commands-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::ensureDirectoryExists($this->workspace.'/tests/Feature');
    File::put($this->workspace.'/routes/web.php', '<?php');
    symlink(dirname(__DIR__, 2).'/vendor', $this->workspace.'/vendor');
    File::put($this->workspace.'/phpunit.xml', '<?xml version="1.0" encoding="UTF-8"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="Workspace"><directory>tests</directory></testsuite></testsuites></phpunit>');
    commitGitWorkspace($this->workspace);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'molly.agent' => 'ollama']);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

/**
 * Run one printed command, such as "php artisan molly:lock-test ID --approve", through Artisan.
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
function runPrintedCommand(string $command): array
{
    $words = explode(' ', $command);
    expect(array_slice($words, 0, 2))->toBe(['php', 'artisan']);
    $parameters = ['task' => $words[3], '--json' => true];
    foreach (array_slice($words, 4) as $option) {
        [$name, $value] = str_contains($option, '=') ? explode('=', $option, 2) : [$option, true];
        $parameters[$name] = $name === '--file' ? [...($parameters[$name] ?? []), $value] : $value;
    }
    $exit = Artisan::call($words[2], $parameters);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('runs the commands molly:story prints, in order, and locks with the derived files', function () {
    $test = '<?php it("greets", function () { $file = __DIR__."/../../app/Greeting.php"; expect(is_file($file) ? require $file : null)->toBe("Hello"); });';
    AcceptanceWriter::fake([[
        'criteria' => ['A caller who loads the greeting receives Hello.'],
        'files' => ['app/Greeting.php'],
    ]])->preventStrayPrompts();
    ChangeWriter::fake([
        ['summary' => 'Write the greeting test.', 'files' => [['path' => 'tests/Feature/GreetingTest.php', 'content' => $test]]],
        ['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]],
    ])->preventStrayPrompts();
    $clean = ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the selected files.']), 'findings' => []];
    TarpitReviewer::fake([$clean, $clean])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);

    Artisan::call('molly:story', [
        'story' => 'Callers can load a greeting that says Hello.',
        '--workspace' => $this->workspace,
        '--test' => 'tests/Feature/GreetingTest.php',
        '--json' => true,
    ]);
    $story = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($story['scope']['files'])->toBe(['app/Greeting.php'])
        ->and($story['next'])->toBe([
            'php artisan molly:start '.$story['id'],
            'php artisan molly:lock-test '.$story['id'].' --approve',
            'php artisan molly:start '.$story['id'],
        ]);

    [, $authoring] = runPrintedCommand($story['next'][0]);
    expect(File::get($this->workspace.'/tests/Feature/GreetingTest.php'))->toBe($test)
        ->and($authoring['report']['changes'][0]['path'])->toBe('tests/Feature/GreetingTest.php');

    [$lockExit, $lock] = runPrintedCommand($story['next'][1]);
    expect($lockExit)->toBe(0)
        ->and($lock['locked'])->toBeTrue()
        ->and($lock['paths'])->toBe(['app/Greeting.php'])
        ->and($lock['scope_source'])->toBe('derived')
        ->and($lock['red_baseline']['classification'])->toBe('missing_behavior');

    [$implementationExit, $implementation] = runPrintedCommand($story['next'][2]);
    expect($implementationExit)->toBe(0)
        ->and($implementation['status'])->toBe('completed')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return "Hello";')
        ->and(Task::findOrFail($story['id'])->paths)->toBe(['app/Greeting.php']);
});

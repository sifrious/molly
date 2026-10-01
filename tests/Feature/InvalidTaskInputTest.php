<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Models\Task;

/** @return array{exit: int, error: string} */
function invalidTask(string $command, array $arguments): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true, '--no-interaction' => true]);
    $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return ['exit' => $exit, 'error' => $document['error'] ?? $document['report']['error']];
}

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/molly-invalid-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

it('gives every missing input a code and an example', function (string $command, array $arguments, string $code): void {
    $result = invalidTask($command, ['--workspace' => $this->workspace, ...$arguments]);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith($code.': ')
        ->and($result['error'])->toContain('--')
        ->and(Task::count())->toBe(0);
})->with([
    'create without a prompt' => ['molly:create', ['--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php'], 'PROMPT_REQUIRED'],
    'create without a test' => ['molly:create', ['prompt' => 'Return Hello', '--file' => ['app/Greeting.php']], 'TEST_REQUIRED'],
    'run without a prompt' => ['molly:run', ['--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php'], 'PROMPT_REQUIRED'],
    'run without a test' => ['molly:run', ['prompt' => 'Return Hello', '--file' => ['app/Greeting.php']], 'TEST_REQUIRED'],
    'story without a story' => ['molly:story', ['--test' => 'tests/Feature/StoryTest.php'], 'STORY_REQUIRED'],
    'story without a test' => ['molly:story', ['story' => 'As a visitor I see Hello.'], 'TEST_REQUIRED'],
]);

it('reports a missing test before refusing an unsafe host', function (): void {
    config(['molly.sandbox.allow_unsafe' => false]);
    File::put($this->workspace.'/composer.json', '{}');

    $result = invalidTask('molly:run', ['prompt' => 'Return Hello', '--workspace' => $this->workspace, '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/MissingTest.php']);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('PROTECTED_TEST_MISSING: tests/Feature/MissingTest.php does not exist');
});

it('names an empty directory that is not a Laravel application without touching it', function (): void {
    $result = invalidTask('molly:create', ['prompt' => 'Return Hello', '--workspace' => $this->workspace, '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php']);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('WORKSPACE_INVALID: '.realpath($this->workspace).' is not a Laravel application')
        ->and(File::exists($this->workspace.'/.git'))->toBeFalse()
        ->and(File::files($this->workspace))->toBe([]);
});

it('names the missing test in a Laravel application', function (): void {
    File::put($this->workspace.'/composer.json', '{}');

    $result = invalidTask('molly:create', ['prompt' => 'Return Hello', '--workspace' => $this->workspace, '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php']);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('PROTECTED_TEST_MISSING: tests/Feature/GreetingTest.php does not exist in '.realpath($this->workspace));
});

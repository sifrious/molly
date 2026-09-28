<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\ChoiceRequired;
use Sifrious\Molly\Mcp\MollyGuide;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\PlanningGuide;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-choices-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    File::put($this->workspace.'/routes/web.php', '<?php');
    writeProtectedTest($this->workspace);
    $this->head = commitGitWorkspace($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

/** @return array<string, mixed> */
function choiceJson(string $command, array $parameters): array
{
    expect(Artisan::call($command, [...$parameters, '--json' => true]))->toBe(1);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('lists the files a task could change when TEST_PROTECTED asks for them', function () {
    $json = choiceJson('molly:create', ['prompt' => 'Return Hello.', '--workspace' => $this->workspace, '--test' => 'tests/GreetingTest.php']);

    expect($json['error'])->toStartWith('TEST_PROTECTED: ')
        ->and($json['error'])->toContain('Choices: routes/web.php, app/Greeting.php.')
        ->and($json['choices'])->toBe([['value' => 'routes/web.php', 'label' => 'routes/web.php'], ['value' => 'app/Greeting.php', 'label' => 'app/Greeting.php']])
        ->and($json['rerun'])->toBe("php artisan molly:create 'Return Hello.' --workspace={$this->workspace} --file=routes/web.php --test=tests/GreetingTest.php --json")
        ->and(Task::count())->toBe(0);
});

it('lists the Pest files when TEST_PATH_INVALID asks for a test', function () {
    $json = choiceJson('molly:create', ['prompt' => 'Return Hello.', '--workspace' => $this->workspace, '--test' => 'app/Greeting.php', '--file' => ['app/Greeting.php']]);

    expect($json['error'])->toStartWith('TEST_PATH_INVALID: ')
        ->and(array_column($json['choices'], 'value'))->toBe(['tests/GreetingTest.php'])
        ->and($json['rerun'])->toContain('--test=tests/GreetingTest.php');
});

it('lists recent commits when COMMIT_REF_INVALID asks for one', function () {
    $json = choiceJson('molly:review-commit', ['ref' => 'no-such-ref', '--workspace' => $this->workspace]);

    expect($json['error'])->toStartWith('COMMIT_REF_INVALID: Git could not resolve no-such-ref as a commit.')
        ->and($json['choices'])->toBe([['value' => $this->head, 'label' => substr($this->head, 0, 7).' Start']])
        ->and($json['rerun'])->toBe("php artisan molly:review-commit {$this->head} --workspace={$this->workspace} --json");
});

it('lists the bundled sources when GUIDE_SOURCE_NOT_FOUND asks for one', function () {
    $ids = array_column(app(PlanningGuide::class)->graph()['sources'], 'id');

    try {
        app(PlanningGuide::class)->source('../../.env');
        $this->fail('The source lookup should ask for a choice.');
    } catch (ChoiceRequired $exception) {
        expect(array_keys($exception->choices))->toBe($ids)
            ->and($exception->getMessage())->toContain('Choices: '.$ids[0]);
    }

    MollyServer::tool(MollyGuide::class, ['operation' => 'source', 'id' => 'missing'])->assertHasErrors()->assertSee('Choices: '.$ids[0]);
});

it('offers files with SCOPE_REQUIRED as JSON and continues after an interactive choice', function () {
    $task = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    writeProtectedTest($this->workspace, contents: '<?php it("returns Hello", fn () => expect(true)->toBeTrue());');

    $json = choiceJson('molly:lock-test', ['task' => $task->id, '--approve' => true]);
    expect($json['error'])->toStartWith('SCOPE_REQUIRED: ')
        ->and(array_column($json['choices'], 'value'))->toBe(['routes/web.php', 'app/Greeting.php'])
        ->and($json['rerun'])->toBe('php artisan molly:lock-test '.$task->id.' --approve --file=routes/web.php --file=app/Greeting.php');

    $this->artisan('molly:lock-test', ['task' => $task->id, '--approve' => true])
        ->expectsChoice('Choose one or more values for file', ['app/Greeting.php'], ['routes/web.php' => 'routes/web.php', 'app/Greeting.php' => 'app/Greeting.php'])
        ->assertSuccessful();

    expect($task->fresh()->paths)->toBe(['app/Greeting.php'])
        ->and($task->fresh()->allow_test_edits)->toBeFalse();
});

it('asks for a test file on a terminal after TEST_PATH_INVALID and saves the task', function () {
    $this->artisan('molly:create', ['prompt' => 'Return Hello.', '--workspace' => $this->workspace, '--test' => 'app/Greeting.php', '--file' => ['app/Greeting.php'], '--name' => 'greeting'])
        ->expectsQuestion('Choose a value for test', 'Greet')
        ->expectsChoice('Choose a value for test', 'tests/GreetingTest.php', ['tests/GreetingTest.php' => 'tests/GreetingTest.php'])
        ->assertSuccessful();

    expect(Task::firstOrFail()->test_path)->toBe('tests/GreetingTest.php');
});

it('names the path and the reason in WORKSPACE_INVALID', function () {
    $json = choiceJson('molly:create', ['prompt' => 'Return Hello.', '--workspace' => $this->workspace.'/missing', '--test' => 'tests/GreetingTest.php', '--file' => ['app/Greeting.php']]);

    expect($json['error'])->toBe('WORKSPACE_INVALID: '.$this->workspace.'/missing does not exist. Choose an existing project directory.')
        ->and($json)->not->toHaveKey('choices');
});

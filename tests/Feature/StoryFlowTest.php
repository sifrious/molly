<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Sifrious\Molly\Actions\CreateTaskFromStory;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-story-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::put($this->workspace.'/routes/web.php', '<?php');
    commitGitWorkspace($this->workspace);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'molly.agent' => 'ollama']);
    $this->story = 'Visitors see a greeting. Signed-in people can press a button to count up; guests cannot.';
    $this->criteria = [
        'A guest who visits the home page sees a greeting.',
        'A signed-in user who presses the counter button sees the count go up by one.',
        'A guest who tries to press the counter button receives HTTP 403.',
    ];
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('saves model-derived acceptance criteria verbatim on a test-authoring task', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria]])->preventStrayPrompts();

    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, ['routes/web.php'], 'tests/Feature/StoryTest.php', 'counter-story');
    $acceptance = $task->source['acceptance'];

    expect($task->allow_test_edits)->toBeTrue()
        ->and($task->status)->toBe('pending')
        ->and($task->nickname)->toBe('counter-story')
        ->and($task->paths)->toContain('routes/web.php', 'tests/Feature/StoryTest.php')
        ->and($task->source['provider'])->toBe('molly-story')
        ->and($task->source['story'])->toBe($this->story)
        ->and($acceptance['criteria'])->toBe($this->criteria)
        ->and($acceptance['text'])->toBe("1. {$this->criteria[0]}\n2. {$this->criteria[1]}\n3. {$this->criteria[2]}")
        ->and($acceptance['provenance']['agent'])->toBe('ollama')
        ->and($acceptance['provenance']['model'])->toBe('local-test-model')
        ->and($acceptance['provenance']['prompt_digest'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($acceptance['provenance']['story_digest'])->toBe(hash('sha256', $this->story))
        ->and($task->prompt)->toContain('tests/Feature/StoryTest.php', $this->story, $acceptance['text']);

    AcceptanceWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'local-test-model' && $prompt->contains('count up'));
});

it('refuses a model answer without usable criteria and saves no task', function (array $answer) {
    AcceptanceWriter::fake([$answer])->preventStrayPrompts();

    expect(fn () => app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php'))
        ->toThrow(RuntimeException::class, 'ACCEPTANCE_INVALID');
    expect(Task::count())->toBe(0);
})->with([
    'empty list' => [['criteria' => []]],
    'blank criterion' => [['criteria' => ['  ']]],
    'missing key' => [['summary' => 'No list.']],
]);

it('checks the story, workspace, and provider before asking the model', function () {
    AcceptanceWriter::fake()->preventStrayPrompts();

    expect(fn () => app(CreateTaskFromStory::class)->handle(' ', $this->workspace, [], 'tests/Feature/StoryTest.php'))
        ->toThrow(RuntimeException::class, 'STORY_INVALID');
    expect(fn () => app(CreateTaskFromStory::class)->handle($this->story, $this->workspace.'/missing', [], 'tests/Feature/StoryTest.php'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_INVALID');

    config(['ai.providers.ollama.url' => 'https://ollama.example.com']);
    expect(fn () => app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php'))
        ->toThrow(RuntimeException::class, 'LOCAL_PROVIDER_INVALID');

    AcceptanceWriter::assertNeverPrompted();
    expect(Task::count())->toBe(0);
});

it('prints the criteria and the next commands from molly:story', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria], ['criteria' => $this->criteria]])->preventStrayPrompts();

    $this->artisan('molly:story', [
        'story' => $this->story,
        '--workspace' => $this->workspace,
        '--test' => 'tests/Feature/StoryTest.php',
        '--file' => ['routes/web.php'],
        '--name' => 'counter-story',
    ])->expectsOutputToContain('1. '.$this->criteria[0])
        ->expectsOutputToContain('php artisan molly:start counter-story')
        ->expectsOutputToContain('php artisan molly:lock-test counter-story --approve --file=routes/web.php')
        ->assertSuccessful();

    Artisan::call('molly:story', [
        'story' => $this->story,
        '--workspace' => $this->workspace,
        '--test' => 'tests/Feature/OtherStoryTest.php',
        '--json' => true,
    ]);
    $json = json_decode(Artisan::output(), true);

    expect($json['acceptance']['criteria'])->toBe($this->criteria)
        ->and($json['next'][0])->toBe('php artisan molly:start '.$json['id'])
        ->and($json['task']['allow_test_edits'])->toBeTrue();
});

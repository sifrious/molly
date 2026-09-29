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
    $this->files = ['routes/web.php', 'resources/views/counter.blade.php'];
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('saves model-derived acceptance criteria verbatim on a test-authoring task', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $this->files]])->preventStrayPrompts();

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
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $this->files], ['criteria' => $this->criteria, 'files' => $this->files]])->preventStrayPrompts();

    $this->artisan('molly:story', [
        'story' => $this->story,
        '--workspace' => $this->workspace,
        '--test' => 'tests/Feature/StoryTest.php',
        '--file' => ['routes/web.php'],
        '--name' => 'counter-story',
    ])->expectsOutputToContain('1. '.$this->criteria[0])
        ->expectsOutputToContain('php artisan molly:start counter-story')
        ->expectsOutputToContain('php artisan molly:lock-test counter-story --approve locks it')
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

it('saves the model-derived implementation files on the task without making them writable for authoring', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $this->files]])->preventStrayPrompts();

    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php');
    $scope = $task->source['scope'];

    expect($task->paths)->toBe(['tests/Feature/StoryTest.php'])
        ->and($scope['files'])->toBe($this->files)
        ->and($scope['rejected'])->toBe([])
        ->and($scope['provenance']['model'])->toBe('local-test-model')
        ->and($scope['provenance']['prompt_digest'])->toBe($task->source['acceptance']['provenance']['prompt_digest'])
        ->and($scope['provenance']['derived_at'])->toBeString();

    AcceptanceWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('"test_path":"tests/Feature/StoryTest.php"')
        && $prompt->contains('"existing_files":["routes/web.php"]'));
});

it('drops derived files outside the workspace rules and records why', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => [
        'routes/web.php',
        '/etc/passwd',
        'app/../.env',
        'tests/Feature/StoryTest.php',
        'vendor/livewire/livewire/src/Component.php',
        '.env',
        '.molly/identity.json',
        '.git/config',
        'database/migrations/2026_01_01_000000_create_counts_table.php',
        'routes/web.php',
        'app/Livewire/Counter.php',
    ]]])->preventStrayPrompts();

    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php');
    $rejected = collect($task->source['scope']['rejected'])->pluck('reason', 'path');

    // The Livewire component brings the view Livewire renders for it.
    expect($task->source['scope']['files'])->toBe(['routes/web.php', 'app/Livewire/Counter.php', 'resources/views/livewire/counter.blade.php'])
        ->and($rejected->keys()->all())->toBe(['/etc/passwd', 'app/../.env', 'tests/Feature/StoryTest.php', 'vendor/livewire/livewire/src/Component.php', '.env', '.molly/identity.json', '.git/config', 'database/migrations/2026_01_01_000000_create_counts_table.php'])
        ->and($rejected['tests/Feature/StoryTest.php'])->toStartWith('TEST_PROTECTED:')
        ->and($rejected['app/../.env'])->toStartWith('PATH_INVALID:')
        ->and($rejected['database/migrations/2026_01_01_000000_create_counts_table.php'])->toStartWith('PATH_INVALID:')
        ->and($task->paths)->toBe(['tests/Feature/StoryTest.php']);
});

it('keeps at most the configured number of implementation files', function () {
    config(['molly.max_files' => 2]);
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => ['routes/web.php', 'app/Counter.php', 'app/Other.php']]])->preventStrayPrompts();

    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php');

    expect($task->source['scope']['files'])->toBe(['routes/web.php', 'app/Counter.php'])
        ->and($task->source['scope']['rejected'][0]['path'])->toBe('app/Other.php')
        ->and($task->source['scope']['rejected'][0]['reason'])->toStartWith('FILES_INVALID:');
});

it('fails with SCOPE_EMPTY and saves no task when no derived file is usable', function (array $files) {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $files]])->preventStrayPrompts();

    expect(fn () => app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php'))
        ->toThrow(RuntimeException::class, 'SCOPE_EMPTY');
    expect(Task::count())->toBe(0);
})->with([
    'no files' => [[]],
    'only invalid files' => [['.env', 'vendor/autoload.php', 'tests/Feature/StoryTest.php']],
]);

it('prints the derived implementation files and the dropped paths from molly:story', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => ['routes/web.php', 'config/app.php']]])->preventStrayPrompts();

    $this->artisan('molly:story', [
        'story' => $this->story,
        '--workspace' => $this->workspace,
        '--test' => 'tests/Feature/StoryTest.php',
    ])->expectsOutputToContain('Implementation files')
        ->expectsOutputToContain('routes/web.php')
        ->expectsOutputToContain('Molly left out config/app.php. PATH_INVALID')
        ->assertSuccessful();
});

it('shows the derived files and the lock command in molly:task after the authoring run', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $this->files]])->preventStrayPrompts();
    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php', 'counter-story');

    Artisan::call('molly:task', ['task' => 'counter-story']);
    expect(Artisan::output())->toContain('File derived for the implementation', 'resources/views/counter.blade.php', 'php artisan molly:start counter-story')
        ->not->toContain('molly:lock-test');

    $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => []]);
    Task::whereKey($task->id)->update(['status' => 'failed']);

    Artisan::call('molly:task', ['task' => 'counter-story']);
    expect(Artisan::output())->toContain('lock it with php artisan molly:lock-test counter-story --approve.');
});

it('checks the packages the story needs against composer.json and composer.lock', function () {
    File::put($this->workspace.'/composer.json', json_encode([
        'require' => ['php' => '^8.3', 'laravel/framework' => '^13.0'],
        'require-dev' => ['pestphp/pest' => '^4.0'],
    ]));
    File::put($this->workspace.'/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework'], ['name' => 'symfony/console']],
        'packages-dev' => [['name' => 'pestphp/pest'], ['name' => 'livewire/livewire']],
    ]));
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $this->files, 'required_packages' => [
        'laravel/framework', 'Livewire/Livewire', 'symfony/console', 'spatie/laravel-counter', 'pestphp/pest', 'not a package', 'livewire/livewire',
    ]]])->preventStrayPrompts();

    Artisan::call('molly:story', ['story' => $this->story, '--workspace' => $this->workspace, '--test' => 'tests/Feature/StoryTest.php', '--json' => true]);
    $json = json_decode(Artisan::output(), true);
    $task = Task::findOrFail($json['id']);

    expect($task->source['scope']['required_packages'])->toBe([
        ['name' => 'laravel/framework', 'status' => 'required', 'command' => null],
        ['name' => 'livewire/livewire', 'status' => 'dev_only', 'command' => 'composer require livewire/livewire'],
        ['name' => 'symfony/console', 'status' => 'transitive', 'command' => 'composer require symfony/console'],
        ['name' => 'spatie/laravel-counter', 'status' => 'missing', 'command' => 'composer require spatie/laravel-counter'],
        ['name' => 'pestphp/pest', 'status' => 'dev_only', 'command' => 'composer require pestphp/pest'],
    ])->and($task->source['scope']['rejected_packages'])->toBe([
        ['name' => 'not a package', 'reason' => 'PACKAGE_INVALID: Not a Composer package name in vendor/package form.'],
    ])->and($json['package_commands'])->toBe([
        'composer require livewire/livewire',
        'composer require symfony/console',
        'composer require spatie/laravel-counter',
        'composer require pestphp/pest',
    ])->and(File::get($this->workspace.'/composer.json'))->not->toContain('livewire');
});

it('tells the user to install a missing package before the implementation run', function () {
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => $this->files, 'required_packages' => ['livewire/livewire']]])->preventStrayPrompts();

    $this->artisan('molly:story', [
        'story' => $this->story,
        '--workspace' => $this->workspace,
        '--test' => 'tests/Feature/StoryTest.php',
    ])->expectsOutputToContain('The implementation run cannot add Composer packages.')
        ->expectsOutputToContain('composer require livewire/livewire (livewire/livewire is not installed)')
        ->assertSuccessful();
});

/** The implementation files gpt-oss:120b-code derived for the RC10 canonical story, in the Livewire 2 layout. */
function rc10DerivedFiles(): array
{
    return ['routes/web.php', 'app/Http/Controllers/AuthController.php', 'app/Http/Livewire/Counter.php', 'resources/views/welcome.blade.php'];
}

it('moves a derived Livewire component to the installed Livewire 4 layout and adds its view', function () {
    File::put($this->workspace.'/composer.lock', json_encode(['packages' => [['name' => 'livewire/livewire', 'version' => 'v4.4.7']], 'packages-dev' => []]));
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => rc10DerivedFiles()]])->preventStrayPrompts();

    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php');
    $scope = $task->source['scope'];

    expect($scope['files'])->toBe(['routes/web.php', 'app/Http/Controllers/AuthController.php', 'app/Livewire/Counter.php', 'resources/views/welcome.blade.php', 'resources/views/livewire/counter.blade.php'])
        ->and($scope['rejected'])->toBe([])
        ->and($scope['adjusted'])->toBe([
            ['path' => 'app/Livewire/Counter.php', 'from' => 'app/Http/Livewire/Counter.php', 'reason' => 'LIVEWIRE_LAYOUT: Livewire 4.4.7 is installed, which resolves components from App\\Livewire in app/Livewire.'],
            ['path' => 'resources/views/livewire/counter.blade.php', 'from' => null, 'reason' => 'LIVEWIRE_VIEW: Livewire renders this view for app/Livewire/Counter.php by convention.'],
        ]);

    AcceptanceWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('"livewire":{"installed_version":"4.4.7","class_namespace":"App\\\\Livewire","class_directory":"app/Livewire","view_directory":"resources/views/livewire"}'));
});

it('keeps Livewire 2 components in app/Http/Livewire and uses the current layout when Livewire is missing', function () {
    File::put($this->workspace.'/composer.lock', json_encode(['packages' => [['name' => 'livewire/livewire', 'version' => 'v2.12.6']], 'packages-dev' => []]));
    AcceptanceWriter::fake([
        ['criteria' => $this->criteria, 'files' => ['routes/web.php', 'app/Livewire/Counter.php']],
        ['criteria' => $this->criteria, 'files' => rc10DerivedFiles()],
    ])->preventStrayPrompts();

    $legacy = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php');
    File::delete($this->workspace.'/composer.lock');
    $missing = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/OtherStoryTest.php');

    expect($legacy->source['scope']['files'])->toBe(['routes/web.php', 'app/Http/Livewire/Counter.php', 'resources/views/livewire/counter.blade.php'])
        ->and($legacy->source['scope']['adjusted'][0]['reason'])->toBe('LIVEWIRE_LAYOUT: Livewire 2.12.6 is installed, which resolves components from App\\Http\\Livewire in app/Http/Livewire.')
        ->and($missing->source['scope']['files'])->toContain('app/Livewire/Counter.php', 'resources/views/livewire/counter.blade.php')
        ->and($missing->source['scope']['adjusted'][0]['reason'])->toBe('LIVEWIRE_LAYOUT: Livewire is not installed, so Molly uses the current layout, which resolves components from App\\Livewire in app/Livewire.');
});

it('does not add a view past the file limit and prints each adjusted path from molly:story', function () {
    config(['molly.max_files' => 1]);
    AcceptanceWriter::fake([['criteria' => $this->criteria, 'files' => ['app/Livewire/Counter.php']], ['criteria' => $this->criteria, 'files' => ['app/Http/Livewire/Counter.php']]])->preventStrayPrompts();

    $task = app(CreateTaskFromStory::class)->handle($this->story, $this->workspace, [], 'tests/Feature/StoryTest.php');

    expect($task->source['scope']['files'])->toBe(['app/Livewire/Counter.php'])
        ->and($task->source['scope']['rejected'])->toBe([['path' => 'resources/views/livewire/counter.blade.php', 'reason' => 'FILES_INVALID: The task already has the maximum of 1 implementation files.']]);

    Artisan::call('molly:story', ['story' => $this->story, '--workspace' => $this->workspace, '--test' => 'tests/Feature/OtherStoryTest.php']);
    $text = preg_replace('/\s+/', ' ', Artisan::output());
    expect($text)->toContain('Molly moved app/Http/Livewire/Counter.php to app/Livewire/Counter.php. LIVEWIRE_LAYOUT:');
});

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Actions\CreateTask;

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('molly.ui.enabled', true);
    config()->set('molly.agent', 'ollama');
    config()->set('molly.model', 'ready-model');
    config()->set('molly.sandbox.allow_unsafe', true);
    config()->set('ai.providers.ollama', ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434']);
    config()->set('ai.providers.typesafe.key', 'typesafe-key-should-not-render');
    $this->workspace = sys_get_temp_dir().'/molly-readiness-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/vendor/bin');
    File::put($this->workspace.'/vendor/bin/pest', "#!/usr/bin/env php\n");
    commitGitWorkspace($this->workspace);
    Http::fake([
        '127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'ready-model', 'size' => 1000]]]),
        '127.0.0.1:11434/api/ps' => Http::response(['models' => []]),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('shows a ready workspace from the navigation and keeps the write probe unknown', function () {
    $plans = DB::table('molly_plans')->count();
    $tasks = DB::table('molly_tasks')->count();

    $this->get('/molly')->assertOk()->assertSee('href="'.route('molly.readiness').'"', false)->assertSee('Readiness');

    $response = $this->get('/molly/readiness?workspace='.urlencode($this->workspace));

    $response->assertOk()
        ->assertSee('Readiness')
        ->assertSee('Refresh checks')
        ->assertSee('name="workspace"', false)
        ->assertSee('for="workspace"', false)
        ->assertSee('type="submit"', false)
        ->assertSee('This workspace has no failed checks')
        ->assertSee('does not install a model')
        ->assertSee('>Passed<', false)
        ->assertSee('>Unknown<', false)
        ->assertSee('database_write_not_probed')
        ->assertSee('Run php artisan molly:doctor')
        ->assertSee('pest_ready')
        ->assertDontSee('typesafe-key-should-not-render')
        ->assertDontSee('molly:install-model')
        ->assertViewHas('report', function (array $report): bool {
            $checks = array_column($report['checks'], null, 'name');

            expect($report['ready'])->toBeTrue()
                ->and($checks['Run history']['status'])->toBe('unknown')
                ->and($checks['Run history']['code'])->toBe('database_write_not_probed')
                ->and($checks['Pest']['status'])->toBe('passed')
                ->and($checks['Model memory']['status'])->not->toBe('failed')
                ->and(array_column($report['checks'], 'status'))->not->toContain('failed');

            return true;
        });

    expect(DB::table('molly_plans')->count())->toBe($plans)
        ->and(DB::table('molly_tasks')->count())->toBe($tasks);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/pull'));
});

it('shows a failed check with the next step from that result', function () {
    File::delete($this->workspace.'/vendor/bin/pest');

    $this->get('/molly/readiness?workspace='.urlencode($this->workspace))
        ->assertOk()
        ->assertSee('This workspace needs action')
        ->assertSee('>Failed<', false)
        ->assertSee('pest_missing')
        ->assertSee('Install Pest in the workspace before running a task.')
        ->assertDontSee('This workspace has no failed checks')
        ->assertViewHas('report.ready', false);
});

it('shows an unmeasured check as unknown instead of passed', function () {
    $path = getenv('PATH') ?: '';
    putenv('PATH=/nonexistent/molly-readiness-path');

    try {
        $this->get('/molly/readiness?workspace='.urlencode($this->workspace))
            ->assertOk()
            ->assertViewHas('report', function (array $report): bool {
                $checks = array_column($report['checks'], null, 'name');

                expect($checks['Git repository']['status'])->toBe('unknown')
                    ->and($checks['Git repository']['code'])->toBe('git_missing')
                    ->and($checks['Git repository']['message'])->toContain('GIT_MISSING')
                    ->and($checks['Git']['status'])->toBe('failed')
                    ->and($checks['Pest']['status'])->toBe('passed');

                return true;
            })
            ->assertSee('>Unknown<', false)
            ->assertSee('>Passed<', false)
            ->assertSee('git_missing');
    } finally {
        putenv('PATH='.$path);
    }
});

it('refuses a remote client and hides the page when the UI is off', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->get('http://localhost/molly/readiness?workspace='.urlencode($this->workspace))
        ->assertForbidden();

    config()->set('molly.ui.enabled', false);
    $this->get('/molly/readiness?workspace='.urlencode($this->workspace))->assertNotFound();
});

it('names a missing workspace and does not run checks', function () {
    $this->get('/molly/readiness?workspace='.urlencode('/nonexistent/molly-readiness'))
        ->assertOk()
        ->assertSee('WORKSPACE_INVALID: /nonexistent/molly-readiness')
        ->assertDontSee('pest_ready')
        ->assertDontSee('database_write_not_probed')
        ->assertViewHas('report', null);
});

it('keeps the doctor write probe on the command path and rolls it back', function () {
    $plans = DB::table('molly_plans')->count();

    $result = app(CheckEnvironment::class)->handle($this->workspace);
    $checks = array_column($result['checks'], null, 'name');

    expect($checks['Run history']['status'])->toBe('passed')
        ->and($checks['Run history']['code'])->toBe('database_ready')
        ->and(DB::table('molly_plans')->count())->toBe($plans);
});

it('puts skip, navigation, the workspace field, and refresh in keyboard order', function () {
    $html = $this->get('/molly/readiness?workspace='.urlencode($this->workspace))
        ->assertOk()
        ->getContent();

    $stops = readinessFocusStops($html);

    expect($stops['labels'])->toBe([
        'Skip to content',
        'Molly tasks',
        'Readiness',
        'Plans',
        'Project graph',
        'Change impact',
        'Learn',
        'Create task',
        'workspace',
        'Refresh checks',
    ])
        ->and($stops['hrefs']['Readiness'])->toBe(route('molly.readiness'))
        ->and($stops['positiveTabindex'])->toBe([])
        ->and($stops['tableLinks'])->toBe(0);

    $this->get('/molly')
        ->assertOk()
        ->assertSee('href="'.route('molly.readiness').'"', false);
});

it('links a task to readiness for that task workspace', function () {
    File::ensureDirectoryExists($this->workspace.'/app');
    File::ensureDirectoryExists($this->workspace.'/tests');
    File::put($this->workspace.'/app/Hello.php', '<?php');
    writeProtectedTest($this->workspace, 'tests/Hello.php');
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/Hello.php');

    $this->get('/molly/tasks/'.$task->id)
        ->assertOk()
        ->assertSee(route('molly.readiness', ['workspace' => $this->workspace]), false);
});

/**
 * Sequential tab stops in document order. A positive tabindex would sort first, so it is reported on its own.
 *
 * @return array{labels: list<string>, hrefs: array<string, string>, positiveTabindex: list<string>, tableLinks: int}
 */
function readinessFocusStops(string $html): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $labels = [];
    $hrefs = [];
    $positive = [];
    foreach ($document->getElementsByTagName('*') as $node) {
        if (! $node instanceof DOMElement) {
            continue;
        }
        $tabindex = $node->getAttribute('tabindex');
        if ($tabindex !== '' && (int) $tabindex < 0) {
            continue;
        }
        if ($tabindex !== '' && (int) $tabindex > 0) {
            $positive[] = $node->tagName.' '.$tabindex;
        }
        $tag = strtolower($node->tagName);
        $disabled = $node->hasAttribute('disabled');
        $focusable = match ($tag) {
            'a' => $node->hasAttribute('href'),
            'button' => ! $disabled,
            'input' => ! $disabled && strtolower($node->getAttribute('type')) !== 'hidden',
            'select', 'textarea' => ! $disabled,
            default => false,
        };
        if (! $focusable) {
            continue;
        }
        $label = $tag === 'input'
            ? $node->getAttribute('id')
            : trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
        $labels[] = $label;
        if ($tag === 'a' && $label !== '') {
            $hrefs[$label] = $node->getAttribute('href');
        }
    }

    return [
        'labels' => $labels,
        'hrefs' => $hrefs,
        'positiveTabindex' => $positive,
        'tableLinks' => $document->getElementsByTagName('table')->length === 0
            ? 0
            : (new DOMXPath($document))->query('//table//a')->length,
    ];
}

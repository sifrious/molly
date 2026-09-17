<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\ReviewCommit;

beforeEach(function () {
    $this->commitWorkspace = sys_get_temp_dir().'/molly-commit-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->commitWorkspace.'/app');
    foreach ([['git', 'init', '-q'], ['git', 'config', 'user.name', 'Molly test'], ['git', 'config', 'user.email', 'molly@example.test']] as $command) {
        expect(Process::path($this->commitWorkspace)->run($command)->successful())->toBeTrue();
    }
    File::put($this->commitWorkspace.'/app/Flag.php', '<?php return false;');
    expect(Process::path($this->commitWorkspace)->run(['git', 'add', 'app/Flag.php'])->successful())->toBeTrue();
    expect(Process::path($this->commitWorkspace)->run(['git', 'commit', '-qm', 'Add a flag'])->successful())->toBeTrue();
});

afterEach(function () {
    File::deleteDirectory($this->commitWorkspace);
});

it('reviews committed PHP changes with citations and never claims tests ran', function () {
    $report = app(ReviewCommit::class)->handle($this->commitWorkspace);

    expect($report['scope'])->toBe('commit')
        ->and($report['diff_bytes'])->toBeGreaterThan(0)
        ->and($report['diff_check']['status'])->toBe('passed')
        ->and($report['tests'])->toBe('not_run')
        ->and($report['evaluation']['status'])->toBe('disabled')
        ->and(array_column($report['citations'], 'id'))->toContain('mary-tarpit', 'laravel-container');
    Http::assertNothingSent();
});

it('only sends staged PHP changes while excluding environment and dependency files', function () {
    File::put($this->commitWorkspace.'/app/Flag.php', '<?php return true;');
    File::put($this->commitWorkspace.'/.env.php', '<?php $secret = "DO_NOT_SEND";');
    File::ensureDirectoryExists($this->commitWorkspace.'/vendor/example');
    File::put($this->commitWorkspace.'/vendor/example/Private.php', '<?php // DO_NOT_SEND');
    File::put($this->commitWorkspace.'/note.txt', 'DO_NOT_SEND');
    Process::path($this->commitWorkspace)->run(['git', 'add', '.']);
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(['model' => 'jev-latest', 'answers' => ['next_action' => ['type' => 'choice', 'choice' => 'continue', 'confidence' => .95, 'probabilities' => ['continue' => .9, 'retry' => .04, 'stop' => .01, 'needs_review' => .05]]]])]);

    $report = app(ReviewCommit::class)->handle($this->commitWorkspace, staged: true);

    expect($report['evaluation']['status'])->toBe('evaluated')->and($report['evaluation']['next_action'])->toBe('continue');
    Http::assertSent(fn ($request) => str_contains($request['state']['review']['diff'], 'return true;')
        && ! str_contains(json_encode($request['state']), 'DO_NOT_SEND')
        && $request['state']['verification']['tests'] === 'not_run'
        && count($request['state']['review']['citations']) === 3);
});

it('reviews PHP changes introduced by a merge against its first parent', function () {
    expect(Process::path($this->commitWorkspace)->run(['git', 'checkout', '-qb', 'feature'])->successful())->toBeTrue();
    File::put($this->commitWorkspace.'/app/Merged.php', "<?php return 'merged';   \n");
    foreach ([['git', 'add', '.'], ['git', 'commit', '-qm', 'Add the feature'], ['git', 'checkout', '-qb', 'integration', 'HEAD~1']] as $command) {
        expect(Process::path($this->commitWorkspace)->run($command)->successful())->toBeTrue();
    }
    File::put($this->commitWorkspace.'/app/Existing.php', "<?php return 'already present';\n");
    foreach ([['git', 'add', '.'], ['git', 'commit', '-qm', 'Add existing behavior'], ['git', 'merge', '--no-ff', 'feature', '-m', 'Merge the feature']] as $command) {
        expect(Process::path($this->commitWorkspace)->run($command)->successful())->toBeTrue();
    }
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(['model' => 'jev-latest', 'answers' => ['next_action' => ['type' => 'choice', 'choice' => 'continue', 'confidence' => .95, 'probabilities' => ['continue' => .9, 'retry' => .04, 'stop' => .01, 'needs_review' => .05]]]])]);

    $report = app(ReviewCommit::class)->handle($this->commitWorkspace);

    expect($report['evaluation']['status'])->toBe('evaluated')
        ->and($report['diff_check']['status'])->toBe('failed')
        ->and($report['diff_check']['output'])->toContain('app/Merged.php', 'trailing whitespace');
    Http::assertSent(fn ($request) => str_contains($request['state']['review']['diff'], "return 'merged';")
        && ! str_contains($request['state']['review']['diff'], 'already present')
        && $request['state']['verification']['diff_check'] === 'failed');
});

it('keeps whitespace failure visible even when semantic evaluation is disabled', function () {
    File::put($this->commitWorkspace.'/app/Flag.php', "<?php return true;   \n");
    Process::path($this->commitWorkspace)->run(['git', 'add', 'app/Flag.php']);

    $report = app(ReviewCommit::class)->handle($this->commitWorkspace, staged: true);

    expect($report['diff_check']['status'])->toBe('failed')->and($report['diff_check']['output'])->toContain('trailing whitespace');
    $this->artisan('molly:review-commit', ['--workspace' => $this->commitWorkspace, '--staged' => true, '--json' => true])->assertExitCode(1);
});

it('does not send a staged empty diff for semantic review', function () {
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);
    $report = app(ReviewCommit::class)->handle($this->commitWorkspace, staged: true);

    expect($report['evaluation']['reason'])->toBe('no_php_changes')->and($report['diff_bytes'])->toBe(0);
    Http::assertNothingSent();
});

it('rejects invalid commit references without evaluating or executing their text', function (string $ref) {
    expect(fn () => app(ReviewCommit::class)->handle($this->commitWorkspace, $ref))->toThrow(RuntimeException::class, 'COMMIT_REF_INVALID');
    Http::assertNothingSent();
})->with(['missing', '--help', 'HEAD; touch unexpected', '$(touch unexpected)']);

it('rejects a large diff instead of silently omitting evidence', function () {
    File::put($this->commitWorkspace.'/app/Flag.php', '<?php // '.str_repeat('x', 16385));
    Process::path($this->commitWorkspace)->run(['git', 'add', 'app/Flag.php']);

    expect(fn () => app(ReviewCommit::class)->handle($this->commitWorkspace, staged: true))->toThrow(RuntimeException::class, 'COMMIT_DIFF_TOO_LARGE');
    Http::assertNothingSent();
});

it('rejects ambiguous command scope and reports unavailable Jev as a failure when enabled', function () {
    $this->artisan('molly:review-commit', ['ref' => 'HEAD', '--staged' => true, '--json' => true])->assertExitCode(1);
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => null]);
    $this->artisan('molly:review-commit', ['--workspace' => $this->commitWorkspace, '--json' => true])->assertExitCode(1);
    Http::assertNothingSent();
});

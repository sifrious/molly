<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ExportTaskJournal;
use Sifrious\Molly\Actions\HandOffTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Contracts\JsonDocument;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Redaction\SecretRedactor;

const PLANTED_APP_KEY = 'base64:q8Zc3VhS0pYk2m1bN7eR4tU6wX9yA0dF5gH8jK1lM3o=';
const PLANTED_TYPESAFE_KEY = 'tsk_live_7Hq2Lm9Vx4Rb8Np3Ws6Yd1';
const PLANTED_GITHUB_TOKEN = 'ghp_A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8';

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-redaction-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    File::put($this->workspace.'/.env', "APP_NAME=Greeter\nAPP_KEY=".PLANTED_APP_KEY."\nTYPESAFE_API_KEY=".PLANTED_TYPESAFE_KEY."\nDB_PASSWORD=secret\n");
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function plantedSecrets(): string
{
    return 'app key '.PLANTED_APP_KEY.', TypeSafe '.PLANTED_TYPESAFE_KEY.', token '.PLANTED_GITHUB_TOKEN;
}

function expectNoPlantedSecrets(string $text): void
{
    expect($text)->not->toContain(PLANTED_APP_KEY)
        ->and($text)->not->toContain(PLANTED_TYPESAFE_KEY)
        ->and($text)->not->toContain(PLANTED_GITHUB_TOKEN);
}

it('replaces secret environment values and token shapes and leaves other text alone', function () {
    $redactor = app(SecretRedactor::class);
    $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA\n-----END RSA PRIVATE KEY-----";

    expect($redactor->text(plantedSecrets(), $this->workspace))
        ->toBe('app key [redacted:APP_KEY], TypeSafe [redacted:TYPESAFE_API_KEY], token [redacted:github_token]')
        ->and($redactor->text('key '.$pem.' sk-proj-abcdefghijklmnopqrstuvwx AKIAABCDEFGHIJKLMNOP eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N'))
        ->toBe('key [redacted:private_key] [redacted:api_key] [redacted:aws_access_key] [redacted:jwt]')
        ->and($redactor->text('Failed asserting that null is "Hello". Greeter secret word and sha256 '.hash('sha256', 'x'), $this->workspace))
        ->toBe('Failed asserting that null is "Hello". Greeter secret word and sha256 '.hash('sha256', 'x'));
});

it('reads process environment secrets as well as the workspace .env', function () {
    putenv('MOLLY_REDACTION_TEST_TOKEN=process-secret-4821');
    try {
        expect(app(SecretRedactor::class)->text('saw process-secret-4821'))->toBe('saw [redacted:MOLLY_REDACTION_TEST_TOKEN]');
    } finally {
        putenv('MOLLY_REDACTION_TEST_TOKEN');
    }
});

it('never stores the values of secret-named settings keys', function () {
    $settings = app(SecretRedactor::class)->settings([
        'runtime' => ['agent' => 'ollama', 'api_key' => 'short', 'max_tokens' => 400],
        'services' => ['github_token' => null, 'webhook_secret' => ['nested' => 'value']],
        'graph_version_key' => '13',
    ]);

    expect($settings)->toBe([
        'runtime' => ['agent' => 'ollama', 'api_key' => '[redacted:api_key]', 'max_tokens' => 400],
        'services' => ['github_token' => null, 'webhook_secret' => '[redacted:webhook_secret]'],
        'graph_version_key' => '13',
    ]);
});

it('redacts planted secrets from model and Pest output in every record Molly writes', function () {
    config(['molly.parallel_checks' => false]);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $checks = array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the selected files.']);
    $checks['F'] = ['status' => 'findings', 'evidence' => 'The model echoed '.plantedSecrets()];
    ChangeWriter::fake([['summary' => 'Return a greeting. Saw '.plantedSecrets(), 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    TarpitReviewer::fake([['checks' => $checks, 'findings' => [[
        'code' => 'F', 'classification' => 'pragmatic', 'severity' => 'warning', 'path' => 'app/Greeting.php', 'line' => 1,
        'problem' => 'Leaked '.plantedSecrets(), 'recommendation' => 'Keep the greeting small.',
    ]]]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'skipped', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'failed', 'tests' => 1, 'failures' => 1,
        'reason' => 'Failed asserting that null is "Hello". '.plantedSecrets(),
        'output' => "FAILED Tests\\GreetingTest\n".plantedSecrets(),
    ]);

    $run = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php', taskId: $task->id);
    app(RecordLifecycleEvent::class)->handle($this->workspace, LifecycleEventType::Failed, $task->id, $run->id, ['reason' => plantedSecrets()]);

    expect($run->status)->toBe('failed');

    $stored = (string) DB::table('molly_runs')->where('id', $run->id)->value('report');
    expectNoPlantedSecrets($stored);
    expect($stored)->toContain('Failed asserting that null is \"Hello\". app key [redacted:APP_KEY], TypeSafe [redacted:TYPESAFE_API_KEY], token [redacted:github_token]')
        ->and($run->report['summary'])->toBe('Return a greeting. Saw app key [redacted:APP_KEY], TypeSafe [redacted:TYPESAFE_API_KEY], token [redacted:github_token]')
        ->and($run->report['verification']['output'])->toStartWith("FAILED Tests\\GreetingTest\n");

    $receipts = File::glob($this->workspace.'/.molly/receipts/'.$run->id.'/*.json');
    expect($receipts)->not->toBeEmpty();
    foreach ($receipts as $receipt) {
        expectNoPlantedSecrets(File::get($receipt));
    }
    $report = Run::findOrFail($run->id)->report;
    $pest = JsonDocument::decode(File::get($this->workspace.'/.molly/receipts/'.$run->id.'/pest.json'));
    $tarpit = JsonDocument::decode(File::get($this->workspace.'/.molly/receipts/'.$run->id.'/tarpit.json'));
    $pestPayload = [
        'status' => $report['verification']['status'], 'tests' => 1, 'assertions' => null, 'failures' => 1, 'errors' => null, 'skipped' => null,
        'reason' => $report['verification']['reason'], 'identified_required_test' => null, 'junit_digest' => null,
    ];
    $tarpitPayload = ['checks' => $report['review']['checks'], 'findings' => $report['review']['findings'], 'reason' => null];
    $context = isset($pest['context']) ? ['context' => $pest['context']] : [];
    expect($pest['evidence_digest'])->toBe(hash('sha256', JsonDocument::encode([...$pestPayload, ...$context])))
        ->and($tarpit['evidence_digest'])->toBe(hash('sha256', JsonDocument::encode([...$tarpitPayload, ...$context])));

    $lifecycle = File::get($this->workspace.'/.molly/lifecycle.jsonl');
    expectNoPlantedSecrets($lifecycle);
    expect($lifecycle)->toContain('[redacted:TYPESAFE_API_KEY]');

    $journal = app(ExportTaskJournal::class)->handle($task->id);
    $markdown = File::get($journal['path']);
    expectNoPlantedSecrets($markdown);
    expect($markdown)->toContain('[redacted:');

    $handoff = app(HandOffTask::class)->handle($task->id, true, (string) Str::uuid(), (string) Str::uuid(), 'implement', 'Implement the locked greeting test.');
    expectNoPlantedSecrets(json_encode($handoff->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    expect(implode("\n", $handoff->priorDiagnostics))->toContain('pest_reason:Failed asserting that null is "Hello". app key [redacted:APP_KEY]');

    Artisan::call('molly:show', ['run' => $run->id, '--json' => true]);
    $show = Artisan::output();
    expectNoPlantedSecrets($show);
    expect($show)->toContain('[redacted:github_token]');

    Artisan::call('molly:task', ['task' => $task->id, '--json' => true]);
    expectNoPlantedSecrets(Artisan::output());
});

it('redacts reports that were saved before redaction existed when they are read', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $id = (string) Str::uuid();
    DB::table('molly_runs')->insert([
        'id' => $id, 'task_id' => $task->id, 'prompt' => 'Return Hello.', 'workspace' => $this->workspace, 'status' => 'failed',
        'report' => json_encode(['verification' => ['status' => 'failed', 'reason' => plantedSecrets()]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('molly:show', ['run' => $id, '--json' => true]);

    expectNoPlantedSecrets(Artisan::output());
    expect(Run::findOrFail($id)->report['verification']['reason'])->toBe('app key [redacted:APP_KEY], TypeSafe [redacted:TYPESAFE_API_KEY], token [redacted:github_token]');
});

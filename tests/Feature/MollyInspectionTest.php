<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\EnsureRunConversation;
use Sifrious\Molly\Actions\InspectRun;
use Sifrious\Molly\Actions\InspectTaskChain;
use Sifrious\Molly\Actions\ShowConversation;
use Sifrious\Molly\Conversations\ConversationStore;
use Sifrious\Molly\Models\Task;

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/molly-inspect-'.Str::uuid();
    File::ensureDirectoryExists($this->home);
    putenv('MOLLY_HOME='.$this->home);
    $_ENV['MOLLY_HOME'] = $this->home;
});

afterEach(function (): void {
    File::deleteDirectory($this->home);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

it('inspects a task with run and conversation links both ways', function (): void {
    $task = Task::create([
        'nickname' => 'inspect-demo',
        'prompt' => 'Ship inspection surfaces',
        'workspace' => base_path(),
        'paths' => ['src/Example.php'],
        'test_path' => 'tests/ExampleTest.php',
        'status' => 'failed',
    ]);

    $run = $task->runs()->create([
        'prompt' => $task->prompt,
        'workspace' => $task->workspace,
        'status' => 'failed',
        'report' => [
            'phase' => 'verification',
            'summary' => 'Could not finish the edit.',
            'error' => 'VERIFICATION_FAILED',
            'verification' => ['status' => 'failed'],
            'effective_config' => ['runtime' => ['agent' => 'ollama', 'model' => 'demo']],
        ],
    ]);

    $chain = app(InspectTaskChain::class)->handle($task->nickname, ensureConversations: true);
    expect($chain['task']['id'])->toBe($task->id)
        ->and($chain['latest_run_id'])->toBe($run->id)
        ->and($chain['conversation_ids'])->not->toBeEmpty()
        ->and($chain['links']['latest_run'])->toBe('molly.run:'.$run->id)
        ->and($chain['effective_config']['runtime']['agent'])->toBe('ollama');

    $runInspection = app(InspectRun::class)->handle($run->id, ensureConversation: false);
    expect($runInspection['links']['task'])->toBe('molly.task:'.$task->id)
        ->and($runInspection['conversation_id'])->toBe($chain['conversation_ids'][0])
        ->and($runInspection['verification']['status'])->toBe('failed');

    $conversationId = $chain['conversation_ids'][0];
    $conversation = app(ShowConversation::class)->handle($conversationId);
    expect($conversation['task_ids'])->toContain($task->id)
        ->and($conversation['run_ids'])->toContain($run->id)
        ->and($conversation['messages'])->not->toBeEmpty()
        ->and($conversation['links']['tasks'][0])->toBe('molly.task:'.$task->id);
});

it('keeps conversation messages ordered with provenance', function (): void {
    $task = Task::create([
        'nickname' => 'order-demo',
        'prompt' => 'First user turn',
        'workspace' => base_path(),
        'paths' => [],
        'test_path' => 'tests/ExampleTest.php',
        'status' => 'completed',
    ]);
    $run = $task->runs()->create([
        'prompt' => 'First user turn',
        'workspace' => $task->workspace,
        'status' => 'completed',
        'report' => [
            'summary' => 'Assistant summary',
            'verification' => ['status' => 'passed'],
        ],
    ]);

    $conversation = (new EnsureRunConversation(app(ConversationStore::class)))->handle($run);
    $roles = array_column($conversation->messages, 'role');
    expect($roles[0])->toBe('user')
        ->and($conversation->messages[0]['provenance']['kind'])->toBe('prompt')
        ->and($conversation->messages[0]['sequence'])->toBe(0)
        ->and($conversation->messages[1]['sequence'])->toBe(1);
});

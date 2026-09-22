<?php

use Sifrious\Molly\Journal\JournalRenderer;

it('renders a task journal as pure Markdown with quoted untrusted content', function () {
    $renderer = new JournalRenderer;
    $markdown = $renderer->renderTask([
        'id' => 'task-1',
        'nickname' => 'Demo',
        'status' => 'open',
        'created_at' => '2026-09-22T00:00:00+00:00',
        'updated_at' => '2026-09-22T01:00:00+00:00',
        'stop_requested_at' => 'No',
        'test_path' => 'tests/DemoTest.php',
        'allow_test_edits' => false,
        'test_digest' => 'deadbeef',
        'paths' => ['app/Demo.php'],
        'prompt' => "Do *stuff*\nwith <script>",
        'runs' => [[
            'id' => 'run-1',
            'status' => 'failed',
            'created_at' => '2026-09-22T00:30:00+00:00',
            'updated_at' => '2026-09-22T00:45:00+00:00',
            'report' => [
                'summary' => 'Needs work',
                'verification' => ['status' => 'failed', 'tests' => 1, 'failures' => 1],
                'verification_receipts' => [['verifier' => 'pest', 'state' => 'fail', 'evidence_digest' => 'abc']],
                'review' => [
                    'status' => 'review_required',
                    'checks' => ['A' => ['status' => 'pass'], 'B' => ['status' => 'fail', 'evidence' => 'leak']],
                    'findings' => [],
                ],
                'complexity_before' => ['status' => 'ok'],
                'complexity_after' => ['status' => 'ok'],
            ],
        ]],
    ]);

    expect($markdown)->toContain('# Task journal')
        ->and($markdown)->toContain('- Task UUID: task\-1')
        ->and($markdown)->toContain('## Editable files')
        ->and($markdown)->toContain('### Attempt 1')
        ->and($markdown)->toContain('#### Pest verification')
        ->and($markdown)->toContain('#### Verification receipts')
        ->and($markdown)->toContain('#### Tarpit review')
        ->and($markdown)->toContain('- A: pass')
        ->and($markdown)->toContain('#### Clever measurements')
        ->and($markdown)->toContain('> Do \\*stuff\\*')
        ->and($markdown)->toContain('&lt;script&gt;');
});

it('quotes non-string untrusted values as not recorded', function () {
    expect((new JournalRenderer)->quote(null))->toBe('> Not recorded');
});

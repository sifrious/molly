<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\EvaluateChanges;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;

it('requires both parallel branches and their evidence before completing a run', function (string $defect, string $expectedStatus) {
    $workspace = sys_get_temp_dir().'/molly-join-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Greeting.php', '<?php return null;');
    config(['molly.parallel_checks' => true]);
    $branches = array_map(fn (string $kind): array => [
        'kind' => $kind, 'branch_id' => $kind.'-1', 'attempt_id' => 'attempt-1', 'execution_target' => 'local',
        'status' => 'passed', 'finished_at' => '2026-09-17T12:00:01Z', 'result_ref' => '/evidence/'.$kind.'.json',
    ], ['verification', 'review']);
    $results = [
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 1],
        'review' => ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => []],
        'branches' => $branches,
    ];
    if (in_array($defect, ['failed', 'running', 'cancelled', 'timed_out'], true)) {
        $results['branches'][1]['status'] = $defect;
    } elseif ($defect === 'missing branch') {
        array_pop($results['branches']);
    } elseif ($defect === 'duplicate branch') {
        $results['branches'][1]['kind'] = 'verification';
    } elseif ($defect === 'missing reference') {
        unset($results['branches'][1]['result_ref']);
    } elseif ($defect === 'unfinished') {
        $results['branches'][1]['finished_at'] = null;
    } elseif ($defect === 'tests failed') {
        $results['verification']['status'] = 'failed';
    } elseif ($defect === 'blocking finding') {
        $results['review']['findings'] = [['severity' => 'blocking']];
    }
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn([
        'summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']],
    ]);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(EvaluateChanges::class)->shouldReceive('handle')->once()->andReturn($results);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');

    try {
        $run = app(RunTask::class)->handle('Return Hello.', $workspace, ['app/Greeting.php', 'tests/GreetingTest.php'], 'tests/GreetingTest.php');

        expect($run->status)->toBe($expectedStatus)
            ->and($run->report['mode'])->toBe('parallel')
            ->and($run->report['branches'])->toBe($results['branches'])
            ->and($run->report['verification'])->toBe($results['verification']);
    } finally {
        File::deleteDirectory($workspace);
    }
})->with([
    ['none', 'completed'], ['failed', 'failed'], ['running', 'failed'], ['cancelled', 'failed'], ['timed_out', 'failed'],
    ['missing branch', 'failed'], ['duplicate branch', 'failed'], ['missing reference', 'failed'], ['unfinished', 'failed'],
    ['tests failed', 'failed'], ['blocking finding', 'failed'],
]);

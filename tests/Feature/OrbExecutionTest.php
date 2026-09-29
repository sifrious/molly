<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * Real queue workers run tasks on two registered local Orbs, each in its own Git worktree, with
 * fake model agents and real Pest checks. These tests prove overlapping execution, the busy and
 * occupied refusals, crash, timeout, cancellation, revocation, duplicate delivery, and that every
 * attempt's evidence survives a worker restart.
 */

beforeEach(function (): void {
    $this->processes = [];
});

afterEach(function (): void {
    foreach ($this->processes as $process) {
        $process->stop(0);
    }
    if (isset($this->root)) {
        $base = dirname($this->root);
        foreach (processesMentioning($base) as $line) {
            @posix_kill((int) $line, SIGKILL);
        }
        File::deleteDirectory($base);
    }
});

/** Remember a worker for cleanup and return it. */
function trackOrbWorker(Process $worker): Process
{
    test()->processes = [...test()->processes, $worker];

    return $worker;
}

/** @return array<string, mixed> the run report of the task's only run, after checking there is exactly one */
function onlyOrbRun(array $state, string $task): array
{
    expect($state['runs'][$task])->toHaveCount(1, json_encode($state['runs'][$task]));

    return $state['runs'][$task][0];
}

it('runs two queued tasks at the same time on two Orbs, each in its own worktree with its own model', function (): void {
    $this->root = $root = orbExecutionFixture(barrier: 2);
    $orbs = dirname($root).'/orbs';
    touch($root.'/hold');

    [$exit, $one] = orbArtisan($root, ['molly:queue', 'one', '--orb=big']);
    expect($exit)->toBe(0, json_encode($one))->and($one['orb']['name'])->toBe('big');
    [$exit, $two] = orbArtisan($root, ['molly:queue', 'two', '--orb-model=gpt-oss:20b']);
    expect($exit)->toBe(0, json_encode($two))->and($two['orb']['name'])->toBe('small')
        ->and($two['queue'])->toBe('molly-orb-'.$two['orb']['id']);

    $bigWorker = trackOrbWorker(orbQueueWorker($root, $one['orb']['id']));
    $smallWorker = trackOrbWorker(orbQueueWorker($root, $two['orb']['id']));
    waitUntil(fn (): bool => count(orbGenerationCalls($root)) === 2, 'both Orbs reached the model call', 30);

    // Both runs are active now. Each Orb is busy with its own task, and a third task is refused.
    [, $listed] = orbArtisan($root, ['molly:orbs']);
    $byName = array_column($listed['orbs'], null, 'name');
    [$busyExit, $busy] = orbArtisan($root, ['molly:queue', 'spare', '--orb=big']);
    [$busyStartExit, $busyStart] = orbArtisan($root, ['molly:start', 'spare', '--orb=small']);
    [$noneExit, $none] = orbArtisan($root, ['molly:queue', 'spare', '--orb-runtime=ollama']);
    $during = orbExecutionState($root);

    unlink($root.'/hold');
    foreach ([$bigWorker, $smallWorker] as $worker) {
        $result = $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getOutput().$worker->getErrorOutput());
    }
    $state = orbExecutionState($root);
    $calls = orbGenerationCalls($root);

    expect($byName['big'])->toMatchArray(['availability' => 'busy', 'health' => 'healthy'])
        ->and($byName['big']['current_task'])->toMatchArray(['reference' => 'one', 'status' => 'running', 'workspace' => $orbs.'/one'])
        ->and($byName['small']['current_task'])->toMatchArray(['reference' => 'two', 'status' => 'running', 'workspace' => $orbs.'/two'])
        ->and($busyExit)->toBe(1)->and($busy['error'])->toStartWith('ORB_BUSY: Orb big is working on task one. Each Orb takes one task at a time.')
        ->and($busyStartExit)->toBe(1)->and($busyStart['report']['error'])->toStartWith('ORB_BUSY: Orb small is working on task two.')
        ->and($noneExit)->toBe(1)->and($none['error'])->toStartWith('ORB_UNAVAILABLE:')->toContain('big: ORB_BUSY')->toContain('small: ORB_BUSY')
        ->and($during['tasks']['one']['status'])->toBe('running')
        ->and($during['tasks']['two']['status'])->toBe('running')
        ->and($during['tasks']['spare']['status'])->toBe('pending');

    // Two processes wrote at once, each with its own Orb's model.
    expect(array_column($calls, 'model'))->toEqualCanonicalizing(['gpt-oss:120b-code', 'gpt-oss:20b'])
        ->and($calls[0]['pid'])->not->toBe($calls[1]['pid']);

    foreach (['one' => 'big', 'two' => 'small'] as $task => $orb) {
        $run = onlyOrbRun($state, $task);
        $target = $run['report']['execution_target'];
        expect($state['tasks'][$task]['status'])->toBe('completed', json_encode($run['report']))
            ->and($run['status'])->toBe('completed')
            ->and($run['workspace'])->toBe($orbs.'/'.$task)
            ->and($target)->toMatchArray(['kind' => 'orb', 'target_id' => $state['orbs'][$orb]['id'], 'provider' => 'local_orb', 'worktree' => $orbs.'/'.$task, 'worktree_root' => $orbs, 'result' => 'completed'])
            ->and($target['orb'])->toMatchArray(['name' => $orb, 'runtime' => 'ollama', 'model' => $state['orbs'][$orb]['model'], 'health' => 'healthy'])
            ->and($target['orb']['runtime_identity'])->toMatchArray(['version' => '0.12.3', 'digest' => hash('sha256', $state['orbs'][$orb]['model'])])
            ->and($target['repository'])->toMatchArray(['path' => $root])
            ->and($target['starting_revision'])->toBe($run['base_sha'])
            ->and($target['prompt_sha256'])->toBe(hash('sha256', 'Return true from the flag.'))
            ->and($target['diff']['status'])->toBe('captured')
            ->and(hash_file('sha256', $target['diff']['path']))->toBe($target['diff']['sha256'])
            ->and(file_get_contents($target['diff']['path']))->toContain('+return true;')
            ->and($run['report']['model'])->toBe($state['orbs'][$orb]['model'])
            ->and($run['report']['model_identity']['model'])->toBe($state['orbs'][$orb]['model'])
            ->and(json_decode($run['effective_config'], true)['config']['model'])->toBe($state['orbs'][$orb]['model'])
            ->and(array_column($run['report']['branches'], 'execution_target'))->toBe([$state['orbs'][$orb]['id'], $state['orbs'][$orb]['id']])
            ->and(array_column($run['report']['branches'], 'model', 'kind')['review'])->toBe($state['orbs'][$orb]['model'])
            ->and(file_get_contents($orbs.'/'.$task.'/app/Flag.php'))->toBe("<?php\nreturn true;\n");

        // The receipt and the journal carry the same Orb identity.
        $receipt = json_decode(file_get_contents($orbs.'/'.$task.'/.molly/receipts/'.$run['id'].'/pest.json'), true);
        $events = array_map(fn (string $line): array => json_decode($line, true), file($orbs.'/'.$task.'/.molly/lifecycle.jsonl', FILE_IGNORE_NEW_LINES));
        $dispatch = array_values(array_filter($events, fn (array $event): bool => $event['type'] === 'dispatch_requested'));
        $finished = array_values(array_filter($events, fn (array $event): bool => $event['type'] === 'verification_finished'));
        expect($receipt['context']['execution_target'])->toMatchArray(['target_id' => $state['orbs'][$orb]['id'], 'worktree' => $orbs.'/'.$task])
            ->and($receipt['context']['execution_target']['diff']['sha256'])->toBe($target['diff']['sha256'])
            ->and($dispatch[0]['payload'])->toMatchArray(['target' => 'orb', 'orb_id' => $state['orbs'][$orb]['id'], 'orb_name' => $orb, 'model' => $state['orbs'][$orb]['model'], 'worktree' => $orbs.'/'.$task, 'starting_revision' => $run['base_sha']])
            ->and($finished[0]['payload']['execution_target'])->toMatchArray(['orb_id' => $state['orbs'][$orb]['id'], 'result' => 'completed', 'diff_sha256' => $target['diff']['sha256']]);
    }

    // The two runs overlapped, wrote only their own worktrees, and freed both Orbs.
    $one = onlyOrbRun($state, 'one');
    $two = onlyOrbRun($state, 'two');
    expect($one['report']['execution_target']['started_at'] <= $two['report']['execution_target']['finished_at'])->toBeTrue()
        ->and($two['report']['execution_target']['started_at'] <= $one['report']['execution_target']['finished_at'])->toBeTrue()
        ->and(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn false;\n")
        ->and(file_get_contents($orbs.'/spare/app/Flag.php'))->toBe("<?php\nreturn false;\n")
        ->and($state['orbs']['big']['current_task_id'])->toBeNull()
        ->and($state['orbs']['small']['current_task_id'])->toBeNull()
        ->and($state['jobs'])->toBe(0)
        ->and($state['failed'])->toBe([]);
});

it('keeps both results inspectable after the Orb workers restart', function (): void {
    $this->root = $root = orbExecutionFixture(barrier: 2);

    $workers = [];
    foreach (['big', 'small'] as $orb) {
        [$exit, $workers[$orb]] = orbArtisan($root, ['molly:worker', 'start', '--orb='.$orb]);
        expect($exit)->toBe(0, json_encode($workers[$orb]))
            ->and($workers[$orb]['state'])->toBe('running')
            ->and($workers[$orb]['orb']['name'])->toBe($orb)
            ->and($workers[$orb]['queue'])->toBe('molly-orb-'.$workers[$orb]['orb']['id'])
            ->and($workers[$orb]['pid_file'])->toBe($root.'/.molly/worker/orb-'.$workers[$orb]['orb']['id'].'.json');
    }
    expect(orbArtisan($root, ['molly:queue', 'one', '--orb=big'])[0])->toBe(0)
        ->and(orbArtisan($root, ['molly:queue', 'two', '--orb=small'])[0])->toBe(0);
    waitUntil(function () use ($root): bool {
        $tasks = orbExecutionState($root)['tasks'];

        return ! in_array($tasks['one']['status'], ['pending', 'running'], true) && ! in_array($tasks['two']['status'], ['pending', 'running'], true);
    }, 'both Orb workers finished their tasks', 60);

    [$exit, $restarted] = orbArtisan($root, ['molly:worker', 'restart', '--orb=big', '--timeout=10']);
    expect($exit)->toBe(0, json_encode($restarted))
        ->and($restarted['state'])->toBe('running')
        ->and($restarted['pid'])->not->toBe($workers['big']['pid'])
        ->and($restarted['stop']['signal'])->toBe('SIGTERM')
        ->and($restarted['recovery'])->toMatchArray(['status' => 'checked', 'recovered' => []]);

    // Fresh processes read the Orbs and both attempts from the saved records.
    [, $listed] = orbArtisan($root, ['molly:orbs']);
    [, $one] = orbArtisan($root, ['molly:task', 'one']);
    [, $two] = orbArtisan($root, ['molly:task', 'two']);
    $state = orbExecutionState($root);
    [$showExit, $shown] = orbArtisan($root, ['molly:show', $state['runs']['one'][0]['id']]);
    [$receiptExit, $receipt] = orbArtisan($root, ['molly:receipt', $state['runs']['two'][0]['id']]);

    foreach (['big', 'small'] as $orb) {
        orbArtisan($root, ['molly:worker', 'stop', '--orb='.$orb, '--timeout=10']);
    }
    $byName = array_column($listed['orbs'], null, 'name');

    expect($byName['big'])->toMatchArray(['availability' => 'available', 'current_task' => null])
        ->and($byName['big']['worker'])->toMatchArray(['state' => 'running', 'pid' => $restarted['pid']])
        ->and($byName['small'])->toMatchArray(['availability' => 'available', 'current_task' => null])
        ->and($byName['small']['worker']['state'])->toBe('running')
        ->and($one['task']['status'] ?? $one['status'])->toBe('completed')
        ->and($two['task']['status'] ?? $two['status'])->toBe('completed')
        ->and($showExit)->toBe(0)
        ->and($shown['report']['execution_target'])->toMatchArray(['target_id' => $state['orbs']['big']['id'], 'result' => 'completed'])
        ->and($receiptExit)->toBe(0)
        ->and(json_encode($receipt))->toContain($state['orbs']['small']['id'])
        ->and(processesMentioning(dirname($root)))->toBe([]);
});

it('runs exactly one attempt when an Orb start is delivered twice', function (): void {
    $this->root = $root = orbExecutionFixture(orbs: ['big' => 'gpt-oss:120b-code'], tasks: ['one'], duplicate: true);

    [$first, $queued] = orbArtisan($root, ['molly:queue', 'one', '--orb=big']);
    [$second] = orbArtisan($root, ['molly:queue', 'one', '--orb=big']);
    expect([$first, $second])->toBe([0, 0]);
    $workers = [trackOrbWorker(orbQueueWorker($root, $queued['orb']['id'])), trackOrbWorker(orbQueueWorker($root, $queued['orb']['id']))];
    foreach ($workers as $worker) {
        $worker->wait();
    }
    $state = orbExecutionState($root);

    expect(count(glob($root.'/reserved-*')))->toBe(2)
        ->and($state['tasks']['one']['status'])->toBe('completed')
        ->and(onlyOrbRun($state, 'one')['report']['execution_target']['result'])->toBe('completed')
        ->and(orbGenerationCalls($root))->toHaveCount(1)
        ->and($state['failed'])->toHaveCount(1)
        ->and($state['failed'][0]['exception'])->toContain('WORKSPACE_BUSY')
        ->and($state['orbs']['big']['current_task_id'])->toBeNull();
});

it('recovers a task whose Orb worker was killed, frees the Orb, and keeps the attempt evidence', function (): void {
    $this->root = $root = orbExecutionFixture(tasks: ['one', 'two']);
    touch($root.'/hold');
    [, $queued] = orbArtisan($root, ['molly:queue', 'one', '--orb=big']);
    $worker = trackOrbWorker(orbQueueWorker($root, $queued['orb']['id']));
    waitUntil(fn (): bool => orbGenerationCalls($root) !== [], 'the Orb worker reached the model call', 30);
    posix_kill($worker->getPid(), SIGKILL);
    waitUntil(fn (): bool => ! $worker->isRunning(), 'the killed worker exited', 5);
    unlink($root.'/hold');
    $crashed = orbExecutionState($root);

    // The next worker start for the Orb settles what the killed worker left running.
    [$exit, $started] = orbArtisan($root, ['molly:worker', 'start', '--orb=big']);
    orbArtisan($root, ['molly:worker', 'stop', '--orb=big', '--timeout=10']);
    $state = orbExecutionState($root);
    $run = onlyOrbRun($state, 'one');
    [$retryExit, $retried] = orbArtisan($root, ['molly:retry', 'one', '--orb=small']);
    $after = orbExecutionState($root);

    expect($worker->getTermSignal())->toBe(SIGKILL)
        ->and($crashed['tasks']['one']['status'])->toBe('running')
        ->and($crashed['orbs']['big']['current_task_id'])->toBe($crashed['tasks']['one']['id'])
        ->and($exit)->toBe(0, json_encode($started))
        ->and($started['recovery']['recovered'])->toBe([['task_id' => $state['tasks']['one']['id'], 'reference' => 'one', 'status' => 'failed', 'reason' => 'worker_exited']])
        ->and($state['tasks']['one']['status'])->toBe('failed')
        ->and($state['orbs']['big']['current_task_id'])->toBeNull()
        ->and($run['status'])->toBe('failed')
        ->and($run['report']['error'])->toStartWith('RUN_ABANDONED:')
        ->and($run['report']['execution_target'])->toMatchArray(['target_id' => $state['orbs']['big']['id'], 'result' => 'failed'])
        ->and($run['report']['execution_target']['finished_at'])->toBe($run['report']['recovery']['recovered_at'])
        ->and($retryExit)->toBe(0, json_encode($retried))
        ->and($retried['report']['execution_target'])->toMatchArray(['target_id' => $state['orbs']['small']['id'], 'result' => 'completed'])
        ->and($after['runs']['one'])->toHaveCount(2)
        ->and(orbGenerationCalls($root))->toHaveCount(2);
});

it('saves a Pest timeout on an Orb as a failed attempt and frees the Orb', function (): void {
    $this->root = $root = orbExecutionFixture(orbs: ['big' => 'gpt-oss:120b-code'], tasks: ['one'], testSleep: 20, testTimeout: 2);
    [, $queued] = orbArtisan($root, ['molly:queue', 'one', '--orb=big']);
    trackOrbWorker(orbQueueWorker($root, $queued['orb']['id']))->wait();
    $state = orbExecutionState($root);
    $run = onlyOrbRun($state, 'one');

    expect($state['tasks']['one']['status'])->toBe('failed')
        ->and($run['status'])->toBe('failed')
        ->and(array_column($run['report']['branches'], 'status', 'kind')['verification'])->toBe('timed_out')
        ->and($run['report']['execution_target']['result'])->toBe('failed')
        ->and($state['orbs']['big']['current_task_id'])->toBeNull()
        ->and(processesMentioning(dirname($root)))->toBe([]);
});

it('stops a running Orb task when asked or when its Orb is revoked, and frees or retires the Orb', function (string $how): void {
    $this->root = $root = orbExecutionFixture(tasks: ['one', 'two']);
    touch($root.'/hold');
    [, $queued] = orbArtisan($root, ['molly:queue', 'one', '--orb=big']);
    $worker = trackOrbWorker(orbQueueWorker($root, $queued['orb']['id']));
    waitUntil(fn (): bool => orbGenerationCalls($root) !== [], 'the Orb worker reached the model call', 30);
    [$exit, $asked] = $how === 'stop' ? orbArtisan($root, ['molly:stop', 'one']) : orbArtisan($root, ['molly:orb-revoke', 'big', '--reason=Retired for the test.']);
    unlink($root.'/hold');
    $worker->wait();
    $state = orbExecutionState($root);
    $run = onlyOrbRun($state, 'one');
    [$againExit, $again] = orbArtisan($root, ['molly:queue', 'two', '--orb=big']);

    expect($exit)->toBe(0, json_encode($asked))
        ->and($state['tasks']['one']['status'])->toBe('stopped')
        ->and($run['status'])->toBe('stopped')
        ->and($run['report']['execution_target']['result'])->toBe('stopped')
        ->and($state['orbs']['big']['current_task_id'])->toBeNull()
        ->and(file_get_contents(dirname($root).'/orbs/one/app/Flag.php'))->toBe("<?php\nreturn false;\n");
    if ($how === 'stop') {
        expect($run['report']['stop_reason'])->toBe('The task received a stop request.')
            ->and($againExit)->toBe(0, json_encode($again));
    } else {
        expect($asked['stopping_task'])->toBe('one')
            ->and($state['orbs']['big']['revoked_reason'])->toBe('Retired for the test.')
            ->and($run['report']['stop_reason'])->toStartWith('ORB_REVOKED: Orb big was revoked during the run')
            ->and($againExit)->toBe(1)
            ->and($again['error'])->toStartWith('ORB_REVOKED: Orb big was revoked');
    }
})->with(['stop', 'revoke']);

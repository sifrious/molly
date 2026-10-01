<?php

namespace Sifrious\Molly;

use RuntimeException;
use Sifrious\Molly\Models\Task;

/**
 * A lock refused because the authored Pest test cannot run. The message names
 * each cause, the affected tests, and the next command. Console commands also
 * print the check and the command in their JSON document.
 */
class AuthoredTestBroken extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $check  the authored test check from RecordRedBaseline
     * @param  array{command: string, reason: string}  $next
     */
    public function __construct(Task $task, public readonly array $check, public readonly array $next)
    {
        parent::__construct('AUTHORED_TEST_BROKEN: Molly did not lock '.$task->test_path.' because it cannot run. '
            .implode(' ', Task::authoredTestProblems($check))
            .' '.$next['reason'].' Run '.$next['command'].'. After you edit the test yourself, run php artisan molly:lock-test '.$task->reference().' --approve again.');
    }
}

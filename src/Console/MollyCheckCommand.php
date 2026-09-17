<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Workspace;
use Throwable;

class MollyCheckCommand extends Command
{
    protected $signature = 'molly:check {input} {output}';

    protected $description = 'Execute one internal verification or review branch';

    protected $hidden = true;

    public function handle(VerifyChanges $verify, ReviewChanges $review): int
    {
        $input = json_decode(file_get_contents((string) $this->argument('input')), true, flags: JSON_THROW_ON_ERROR);
        $output = (string) $this->argument('output');
        if (! is_array($input) || ! in_array($input['kind'] ?? null, ['verification', 'review'], true) || $output !== ($input['result_ref'] ?? null)) {
            throw new RuntimeException('CHECK_INPUT_INVALID: Invalid internal check input.');
        }
        if (! function_exists('posix_setsid') || ! function_exists('posix_kill') || posix_setsid() === -1) {
            throw new RuntimeException('CHECK_PROCESS_GROUP_UNAVAILABLE: Parallel checks require POSIX process groups.');
        }
        file_put_contents($output.'.group', (string) getmypid(), LOCK_EX);
        chmod($output.'.group', 0600);
        config($input['config']);
        try {
            $result = (new Workspace($input['workspace']))->duringCheck((string) ($input['workspace_lease'] ?? ''), fn (): array => $input['kind'] === 'verification'
                ? $verify->handle($input['workspace'], $input['test_path'], $input['evidence_directory'])
                : $review->handle($input['prompt'], $input['before'], $input['after']));
            $passed = $input['kind'] === 'verification' ? ($result['status'] ?? null) === 'passed' : $review->passed($result);
            $failure = $passed ? null : ($result['reason'] ?? 'review_blocking');
        } catch (Throwable $exception) {
            $passed = false;
            $failure = $input['kind'].'_failed';
            $result = ['status' => 'failed', 'checks' => [], 'findings' => [], 'error' => $exception->getMessage(), 'reason' => $failure];
        }
        $envelope = ['branch_id' => $input['branch_id'], 'attempt_id' => $input['attempt_id'], 'status' => $passed ? 'passed' : 'failed', 'failure_classification' => $failure, 'result' => $result];
        $mask = umask(0077);
        $temporary = $output.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (file_put_contents($temporary, json_encode($envelope, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false || ! rename($temporary, $output)) {
                throw new RuntimeException('CHECK_RESULT_UNWRITABLE: Cannot save check evidence.');
            }
        } finally {
            umask($mask);
            @unlink($temporary);
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}

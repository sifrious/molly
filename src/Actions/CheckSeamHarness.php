<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Execution\Sandbox;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\SeamSteps;
use Sifrious\Molly\Seams\StepHandler;
use Sifrious\Molly\Seams\StepResult;
use Sifrious\Molly\Verification\VerificationState;
use Symfony\Component\Process\Process;

final class CheckSeamHarness implements StepHandler
{
    public function __construct(private Sandbox $sandbox) {}

    public function argumentSchema(): array
    {
        return SeamSteps::emptyArguments();
    }

    public function handle(SeamRevision $revision, Run $attempt, array $arguments, string $evidenceDirectory): StepResult
    {
        $this->sandbox->refuseSafeWorkflow();
        $snapshot = $revision->snapshot;
        $workspace = $snapshot['workspace'];
        if (! is_file($workspace.'/vendor/bin/pest') || ! is_file($workspace.'/artisan')) {
            throw new SeamError('ENVIRONMENT_UNAVAILABLE', 'Install Pest and use a Laravel application with an Artisan entry point.', $workspace);
        }
        $available = ['php', 'pest', 'laravel-http', 'laravel-console'];
        if (array_diff($snapshot['capabilities'], $available) !== []) {
            throw new SeamError('CLIENT_CAPABILITY_UNSUPPORTED', 'This worker cannot perform every required platform check.', 'manifest.json', $snapshot['capabilities'], $available);
        }
        $argv = [PHP_BINARY, '-l', $workspace.'/'.$snapshot['preview']['path']];
        $process = new Process($argv, $workspace, timeout: 15);
        $started = hrtime(true);
        $process->run();
        $observed = ['command' => $argv, 'exit_code' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput(),
            'duration_ms' => (hrtime(true) - $started) / 1_000_000, 'sandbox' => $this->sandbox->available() && ! $this->sandbox->allowUnsafe()];

        return new StepResult($process->isSuccessful() ? VerificationState::Pass : VerificationState::Fail,
            ['syntax' => 'valid', 'capabilities' => $snapshot['capabilities']], $observed,
            $process->isSuccessful() ? null : new SeamError('TEST_SYNTAX_INVALID', 'Repair the test template and create a new revision.', $snapshot['preview']['path']));
    }
}

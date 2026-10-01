<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\SeamWorkflow;
use Sifrious\Molly\Seams\SeamError;

use function Laravel\Prompts\note;

class MollySeamCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:seam {operation : list, inspect, compare, validate, plan, preview, write, advance, status, report, cancel, acknowledge, or resume}
        {--workspace= : Consuming application directory}
        {--seam= : Package/seam ID from list}
        {--contract= : JSON input contract file}
        {--plan= : Completed Molly planning review ID}
        {--revision= : Saved seam revision ID}
        {--digest= : Reviewed revision SHA-256}
        {--step= : Next permitted step ID}
        {--key= : Idempotency key for this transition}
        {--actor= : author, implementer, verifier, or coordinator}
        {--candidate-digest= : Handoff candidate SHA-256}
        {--evidence-digest= : Handoff evidence SHA-256}
        {--recipient= : Receiving agent identifier}
        {--wait : Execute the bounded step locally instead of queuing it}
        {--json : Print structured output}';

    protected $description = 'Inspect editable seam instructions and execute verified plan steps';

    public function handle(SeamWorkflow $workflow): int
    {
        try {
            $input = ['operation' => $this->argument('operation')];
            foreach (['workspace', 'seam', 'plan', 'revision', 'digest', 'step', 'key', 'actor', 'recipient', 'candidate-digest', 'evidence-digest'] as $name) {
                if ($this->option($name) !== null) {
                    $input[str_replace('-', '_', $name)] = $this->option($name);
                }
            }
            $input['workspace'] ??= base_path();
            if ($this->option('contract') !== null) {
                $path = $this->option('contract');
                if (! is_file($path) || ! is_readable($path) || filesize($path) > 262144) {
                    throw new SeamError('INPUT_INVALID', 'Choose a readable JSON contract no larger than 256 KiB.', $path);
                }
                $input['contract'] = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            }
            $result = $workflow->handle($input, (bool) $this->option('wait'));
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                note(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            return isset($result['run_status']) && in_array($result['run_status'], ['failed', 'stopped'], true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['error' => SeamError::from($exception)->toArray()]);
        }
    }
}

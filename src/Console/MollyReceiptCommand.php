<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Sifrious\Molly\Contracts\VerificationOutcome;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Workspace;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

class MollyReceiptCommand extends Command
{
    protected $signature = 'molly:receipt {run : Saved run ID} {--workspace= : Repository path that holds .molly/receipts} {--json : Print JSON only}';

    protected $description = 'Read immutable verification receipts for a run without rerunning verification';

    public function handle(): int
    {
        try {
            $runId = (string) $this->argument('run');
            $workspace = $this->resolveWorkspace($runId);
            $directory = (new Workspace($workspace))->path.'/.molly/receipts/'.$runId;
            if (! is_dir($directory) || is_link($directory)) {
                throw new RuntimeException('RECEIPT_NOT_FOUND: No verification receipts exist for that run in '.$directory.'.');
            }

            $receipts = [];
            foreach (File::files($directory) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }
                $outcome = VerificationOutcome::fromJson(trim(File::get($file->getPathname())));
                $receipts[] = [...$outcome->toArray(), 'path' => $file->getPathname()];
            }

            usort($receipts, fn (array $a, array $b): int => strcmp((string) $a['verifier'], (string) $b['verifier']));

            if ($receipts === []) {
                throw new RuntimeException('RECEIPT_NOT_FOUND: The receipt directory exists but contains no verifier JSON files.');
            }

            if ($this->option('json')) {
                $this->line(json_encode([
                    'run' => $runId,
                    'workspace' => (new Workspace($workspace))->path,
                    'receipts' => $receipts,
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                intro('Molly verification receipts');
                note('Run: '.$runId);
                note('Workspace: '.(new Workspace($workspace))->path);
                foreach ($receipts as $receipt) {
                    $this->line('');
                    $this->line($receipt['verifier'].' — '.$receipt['state'].' ('.$receipt['policy'].', '.$receipt['failure_action'].')');
                    $this->line('  evidence_digest: '.$receipt['evidence_digest']);
                    $this->line('  started_at: '.$receipt['started_at']);
                    $this->line('  finished_at: '.$receipt['finished_at']);
                    $this->line('  another_attempt_permitted: '.($receipt['another_attempt_permitted'] ? 'yes' : 'no'));
                    if ($receipt['diagnostics_ref'] !== null) {
                        $this->line('  diagnostics_ref: '.$receipt['diagnostics_ref']);
                    }
                    $this->line('  path: '.$receipt['path']);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'run' => (string) $this->argument('run'),
                    'status' => 'error',
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function resolveWorkspace(string $runId): string
    {
        $option = $this->option('workspace');
        if (is_string($option) && trim($option) !== '') {
            return $option;
        }

        $run = Run::find($runId);
        if ($run !== null && is_string($run->workspace) && $run->workspace !== '') {
            return $run->workspace;
        }

        return base_path();
    }
}

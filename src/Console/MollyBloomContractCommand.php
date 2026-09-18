<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ExportBloomContract;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyBloomContractCommand extends Command
{
    protected $signature = 'molly:bloom-contract {task : Saved task name or ID} {--workspace-id= : Bloom workspace UUID} {--branch= : Existing Bloom branch} {--base-sha= : 40-character merge-base SHA} {--json : Print JSON only}';

    protected $description = 'Print the versioned task contract for an existing Bloom workspace';

    public function handle(ExportBloomContract $export): int
    {
        try {
            $contract = $export->handle(
                (string) $this->argument('task'),
                (string) $this->option('workspace-id'),
                (string) $this->option('branch'),
                (string) $this->option('base-sha'),
            );
            $payload = $contract->toArray();
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note('Bloom workspace: '.$contract->bloomWorkspaceId);
                note('Branch: '.$contract->branch);
                note('Protected test: '.$contract->protectedPaths[0]);
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}

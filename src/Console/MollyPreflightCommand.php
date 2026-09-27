<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\InspectHardware;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class MollyPreflightCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:preflight {--destination= : Directory that will hold Ollama models (default: OLLAMA_MODELS or ~/.ollama/models)} {--json : Print the snapshot as JSON only}';

    protected $description = 'Measure memory, acceleration, Ollama, and model disk space on this host without choosing a model';

    public function handle(InspectHardware $inspect): int
    {
        $destination = $this->option('destination');
        $run = fn (): array => $inspect->handle(is_string($destination) && $destination !== '' ? $destination : null);

        try {
            $snapshot = $this->option('json') ? $run() : spin($run, 'Measuring this host');
        } catch (Throwable $e) {
            return $this->reportFailure('PREFLIGHT_FAILED: Molly could not produce a hardware snapshot. '.$e->getMessage(), ['error' => 'preflight_failed', 'message' => $e->getMessage()]);
        }

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        intro('Molly preflight');
        $rows = [];
        foreach ($snapshot['facts'] as $group => $facts) {
            foreach ($facts as $name => $fact) {
                $rows[] = [
                    "{$group}.{$name}",
                    $fact['status'] === 'measured' ? $this->display($fact['value']) : 'unknown',
                    is_array($fact['source']) ? implode(', ', $fact['source']) : $fact['source'],
                ];
            }
        }
        table(['Fact', 'Value', 'Source'], $rows);

        foreach ($snapshot['unknowns'] as $unknown) {
            warning("{$unknown['fact']}: {$unknown['reason']}");
        }

        outro("Snapshot {$snapshot['snapshot_sha256']}. Molly measured these facts only; it did not choose a model.");

        return self::SUCCESS;
    }

    private function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) && array_is_list($value) => $value === [] ? 'none' : implode(', ', array_map(fn ($item): string => is_array($item) ? (string) ($item['name'] ?? json_encode($item)) : (string) $item, $value)),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }
}

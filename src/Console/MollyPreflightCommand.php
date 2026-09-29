<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\DecideModelFit;
use Sifrious\Molly\Actions\InspectHardware;
use Sifrious\Molly\ModelFit\InstallationHeadroom;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class MollyPreflightCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:preflight {--destination= : Directory that will hold Ollama models (default: OLLAMA_MODELS or ~/.ollama/models)} {--snapshot= : Decide from a saved molly:preflight --json document instead of measuring this host} {--json : Print the snapshot and the fit decision as JSON only}';

    protected $description = 'Measure this Mac and decide which approved Ollama model fits, without downloading anything';

    public function handle(InspectHardware $inspect, DecideModelFit $decide): int
    {
        $destination = $this->option('destination');
        $saved = $this->option('snapshot');
        $run = function () use ($inspect, $decide, $destination, $saved): array {
            $snapshot = is_string($saved) && $saved !== '' ? $this->saved($saved) : $inspect->handle(is_string($destination) && $destination !== '' ? $destination : null);

            return $snapshot + ['decision' => $decide->handle($snapshot)];
        };

        try {
            $snapshot = $this->option('json') ? $run() : spin($run, 'Measuring this host');
        } catch (Throwable $e) {
            $message = preg_match('/\A[A-Z][A-Z0-9_]+: /', $e->getMessage()) === 1 ? $e->getMessage() : 'PREFLIGHT_FAILED: Molly could not produce a hardware snapshot. '.$e->getMessage();

            return $this->reportFailure($message, ['error' => strtolower((string) strtok($message, ':')), 'message' => $message]);
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

        $this->decision($snapshot['decision']);
        outro("Snapshot {$snapshot['snapshot_sha256']}. Decision {$snapshot['decision']['decision_sha256']}. Molly downloaded nothing.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function saved(string $path): array
    {
        $document = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($document)) {
            throw new RuntimeException('SNAPSHOT_INVALID: '.$path.' is not a readable molly:preflight --json document.');
        }
        unset($document['decision']);

        return $document;
    }

    /** @param  array<string, mixed>  $decision */
    private function decision(array $decision): void
    {
        $fits = in_array($decision['status'], ['recommended_fit', 'minimum_fit', 'already_installed'], true);
        $fits ? info('Model fit: '.$decision['status'].'. '.$decision['message']) : warning('Model fit: '.$decision['status'].'. '.$decision['message']);
        if ($decision['reasons'] !== []) {
            note('Reasons: '.implode(', ', $decision['reasons']));
        }

        $runtime = $decision['runtime_selection'];
        if ($runtime !== null) {
            note('Runtime: '.$runtime['runtime'].' '.$runtime['version'].' is pinned. This Mac reports CLI '.($runtime['installed']['cli_version'] ?? 'unknown').' and API '.($runtime['installed']['api_version'] ?? 'unknown').': '.$runtime['status'].'.');
        }

        if ($decision['candidates'] !== []) {
            table(['Approved model', 'Status', 'Memory needed', 'Download', 'Reasons and constraints'], array_map(fn (array $candidate): array => [
                $candidate['model'],
                $candidate['status'],
                InstallationHeadroom::gigabytes($candidate['required']['minimum_memory_bytes']).' minimum, '.InstallationHeadroom::gigabytes($candidate['required']['recommended_memory_bytes']).' with headroom',
                $candidate['installed'] ? 'installed' : InstallationHeadroom::gigabytes($candidate['download_bytes']),
                implode(', ', [...$candidate['reasons'], ...$candidate['constraints']]),
            ], $decision['candidates']));
        }

        if ($decision['installed_models'] !== []) {
            table(['Installed model', 'Admission', 'Reasons'], array_map(fn (array $model): array => [
                $model['name'], $model['admission'], implode(', ', $model['reasons']),
            ], $decision['installed_models']));
        }

        foreach ($decision['flags'] as $flag) {
            warning('Flag: '.$flag);
        }

        if ($decision['install_blockers'] !== []) {
            note('Molly will not install a model now: '.implode(', ', $decision['install_blockers']).'.');
        }
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

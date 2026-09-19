<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyGraphsBootstrapCommand extends Command
{
    protected $signature = 'molly:graphs-bootstrap
        {path? : Laravel project root (default: current app)}
        {--json : Print JSON only}';

    protected $description = 'Bootstrap version-pinned Laravel and dependency knowledge graphs for a Molly project';

    public function handle(BootstrapProjectKnowledgeGraphs $action): int
    {
        try {
            $path = $this->argument('path');
            $path = is_string($path) && trim($path) !== '' ? $path : base_path();

            $result = $action->handle(
                $path,
                $this->option('json') ? null : function (string $step, string $message): void {
                    note('['.$step.'] '.$message);
                },
            );

            $payload = [
                'status' => $result['ok'] ? 'ok' : 'partial',
                'manifest_path' => $result['manifest_path'],
                'laravel_exact' => $result['laravel_exact'],
                'units' => $result['units'],
            ];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Graph manifest: '.$result['manifest_path']);
            }

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}

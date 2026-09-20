<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\CheckEnvironment;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class MollyDoctorCommand extends Command
{
    protected $signature = 'molly:doctor {--workspace= : Workspace containing Pest} {--json : Print JSON only}';

    protected $description = 'Check Ollama readiness, Pest, run history, sandbox, and Clever without printing secrets';

    public function handle(CheckEnvironment $check): int
    {
        $run = fn (): array => $check->handle((string) ($this->option('workspace') ?: base_path()));
        if ($this->option('json')) {
            $result = $run();
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
        } else {
            intro('Check Molly');
            $result = spin($run, 'Checking local requirements');
            table(['Check', 'Status', 'Code', 'Details'], array_map(fn (array $row): array => [$row['name'], $row['status'], $row['code'], $row['message']], $result['checks']));
            outro($result['ready'] ? 'Molly is ready.' : 'Resolve the failed checks before running a task.');
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}

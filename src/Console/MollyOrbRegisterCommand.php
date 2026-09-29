<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class MollyOrbRegisterCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:orb-register
        {name : A name for the Orb, such as big}
        {--runtime=ollama : The agent runtime, ollama or amp}
        {--model= : The installed Ollama model the Orb runs, such as gpt-oss:20b}
        {--repository= : The Git checkout the Orb may change (defaults to the application)}
        {--worktree-root= : The existing directory that holds the task worktrees this Orb may write}
        {--json : Print JSON only}';

    protected $description = 'Register a local Orb with a stable ID, a runtime and model, one repository, and an approved worktree root';

    public function handle(LocalOrbProvider $orbs): int
    {
        try {
            $root = $this->option('worktree-root');
            if (! is_string($root) || trim($root) === '') {
                throw new RuntimeException('ORB_WORKTREE_ROOT_REQUIRED: Pass --worktree-root with the directory that holds the Orb\'s task worktrees, such as ../orbs.');
            }
            $orb = $orbs->register(
                (string) $this->argument('name'),
                (string) $this->option('runtime'),
                $this->option('model'),
                (string) ($this->option('repository') ?: base_path()),
                $root,
            );
            $described = $orbs->describeOrb($orb);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'ok', 'orb' => $described], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        note('Registered Orb '.$described['name'].' as '.$described['id'].'.');
        table(['Field', 'Value'], [
            ['Availability', $described['availability']],
            ['Health', $described['health']],
            ['Runtime / model', $described['runtime'].' / '.($described['model'] ?? 'chosen by Amp')],
            ['Repository', $described['repository']['path']],
            ['Worktree root', $described['worktree_root']],
            ['Queue', $described['queue']],
        ]);
        if ($described['health'] !== 'healthy') {
            warning('The Orb failed its health check and takes no task until it passes. '.$described['health_reason']);
        }

        return self::SUCCESS;
    }
}

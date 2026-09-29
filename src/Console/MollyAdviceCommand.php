<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyAdviceCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:advice {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Explain the next step for a saved task without executing it';

    public function handle(RecommendTaskNextStep $action): int
    {
        try {
            $advice = $action->handle((string) $this->argument('task'));
            if ($this->option('json')) {
                $this->writeJson($advice);
            } else {
                note('Task '.$advice['task_reference'].' / '.$advice['observed']['task_status']);
                note($advice['reason']);
                table(['Decision', 'Result'], [
                    ['Recommended action', ucfirst($advice['next_action'])],
                    ['Retry allowed', $advice['retry_allowed'] ? 'Yes' : 'No'],
                    ['Attempts used', $advice['observed']['attempt_count'].' / '.($advice['observed']['max_attempts'] ?? 'Invalid limit')],
                    ['Jev', ucfirst(str_replace('_', ' ', $advice['provider']['status']))],
                ]);
                if ($advice['confidence'] !== null) {
                    note('Jev confidence: '.$advice['confidence'].'. Required threshold: '.($advice['provider']['threshold'] ?? 'Not configured').'.');
                }
                if ($advice['command'] !== null) {
                    note('Next command: '.$advice['command']);
                }
                note('Advice does not start, retry, or stop a task.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['task' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}

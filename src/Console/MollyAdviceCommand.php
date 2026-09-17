<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyAdviceCommand extends Command
{
    protected $signature = 'molly:advice {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Explain the next step for a saved task without executing it';

    public function handle(RecommendTaskNextStep $action): int
    {
        try {
            $advice = $action->handle((string) $this->argument('task'));
            if ($this->option('json')) {
                $this->line(json_encode($advice, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note('Task '.$advice['task_reference'].' / '.$advice['observed']['task_status']);
                note($advice['reason']);
                table(['Decision', 'Result'], [
                    ['Recommended action', $advice['next_action']],
                    ['Retry allowed', $advice['retry_allowed'] ? 'Yes' : 'No'],
                    ['Attempts used', $advice['observed']['attempt_count'].' / '.($advice['observed']['max_attempts'] ?? 'Invalid limit')],
                    ['TypeSafe', $advice['provider']['status'].' / '.$advice['provider']['reason']],
                ]);
                if ($advice['confidence'] !== null) {
                    note('TypeSafe confidence: '.$advice['confidence'].'. Required threshold: '.($advice['provider']['threshold'] ?? 'Not configured').'.');
                }
                if ($advice['command'] !== null) {
                    note('Next command: '.$advice['command']);
                }
                note('Advice does not start, retry, or stop a task.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['task' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}

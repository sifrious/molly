<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Throwable;

use function Laravel\Prompts\note;

class MollyOrbRevokeCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:orb-revoke
        {orb : Orb name or ID}
        {--reason= : Why the Orb is revoked, saved with it}
        {--json : Print JSON only}';

    protected $description = 'Revoke an Orb so it takes no new task, and stop the task it is running at the next step';

    public function handle(LocalOrbProvider $orbs): int
    {
        try {
            $result = $orbs->revoke((string) $this->argument('orb'), $this->option('reason'));
            $described = $orbs->describeOrb($result['orb']);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }

        $released = $result['released_task']?->reference();
        $stopping = $result['stopping_task']?->reference();
        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'ok', 'orb' => $described, 'already_revoked' => $result['already_revoked'], 'released_task' => $released, 'stopping_task' => $stopping], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        note($result['already_revoked']
            ? 'Orb '.$described['name'].' was already revoked at '.$described['revoked_at'].'.'
            : 'Revoked Orb '.$described['name'].' ('.$described['id'].'). It keeps its ID and history and takes no new task.');
        if ($released !== null) {
            note('Task '.$released.' was queued on this Orb. It is still pending. Queue it on another Orb with php artisan molly:queue '.$released.' --orb=NAME.');
        }
        if ($stopping !== null) {
            note('Task '.$stopping.' is running on this Orb. Molly stops it at the next step and saves the run as stopped.');
        }

        return self::SUCCESS;
    }
}

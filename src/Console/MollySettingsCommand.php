<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\GetMollySettings;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollySettingsCommand extends Command
{
    protected $signature = 'molly:settings {--json : Print JSON only}';

    protected $description = 'Show Molly global settings (documented defaults merged with persisted overrides)';

    public function handle(GetMollySettings $action): int
    {
        try {
            $payload = ['status' => 'ok', ...$action->handle()];
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Settings file: '.$payload['path']);
                note(json_encode($payload['settings'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
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

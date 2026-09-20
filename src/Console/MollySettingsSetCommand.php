<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\UpdateMollySettings;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollySettingsSetCommand extends Command
{
    protected $signature = 'molly:settings-set
        {--patch= : JSON object of settings overrides}
        {--json : Print JSON only}';

    protected $description = 'Update Molly global settings (does not rewrite historical run snapshots)';

    public function handle(UpdateMollySettings $action): int
    {
        try {
            $raw = $this->option('patch');
            if (! is_string($raw) || trim($raw) === '') {
                throw new RuntimeException('SETTINGS_PATCH_REQUIRED: Pass --patch=\'{"loop":{"max_iterations":5}}\'.');
            }
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('SETTINGS_PATCH_INVALID: --patch must be a JSON object.');
            }
            if (! is_array($decoded)) {
                throw new RuntimeException('SETTINGS_PATCH_INVALID: --patch must be a JSON object.');
            }

            $payload = ['status' => 'ok', ...$action->handle($decoded)];
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Updated '.$payload['path']);
                note('Historical run effective_config values are unchanged.');
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

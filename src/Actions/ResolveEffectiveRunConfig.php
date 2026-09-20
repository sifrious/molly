<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Settings\MollySettings;
use Sifrious\Molly\Settings\SettingsStore;

/**
 * Build an immutable effective-config snapshot for a run.
 *
 * Snapshot = documented defaults ← persisted globals ← task overrides.
 */
final class ResolveEffectiveRunConfig
{
    public function __construct(private SettingsStore $store = new SettingsStore) {}

    /**
     * @param  array<string, mixed>  $taskOverrides
     * @return array{schema_version: int, captured_at: string, source: array{globals_path: string}, config: array<string, mixed>}
     */
    public function handle(array $taskOverrides = []): array
    {
        $globals = $this->store->read();
        $config = MollySettings::merge($globals->toArray(), $taskOverrides);

        return [
            'schema_version' => MollySettings::SCHEMA_VERSION,
            'captured_at' => gmdate('c'),
            'source' => [
                'globals_path' => $this->store->path(),
            ],
            'config' => $config,
        ];
    }
}

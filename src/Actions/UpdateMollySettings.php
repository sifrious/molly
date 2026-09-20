<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Settings\MollySettings;
use Sifrious\Molly\Settings\SettingsStore;

final class UpdateMollySettings
{
    public function __construct(private SettingsStore $store = new SettingsStore) {}

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{schema_version: int, defaults: array<string, mixed>, settings: array<string, mixed>, path: string}
     */
    public function handle(array $overrides): array
    {
        $this->assertKnownShape($overrides);
        $current = $this->store->read();
        $merged = MollySettings::fromArray(MollySettings::merge($current->toArray(), $overrides));
        $this->store->write($merged);

        return [
            'schema_version' => MollySettings::SCHEMA_VERSION,
            'defaults' => MollySettings::DEFAULTS,
            'settings' => $merged->toArray(),
            'path' => $this->store->path(),
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function assertKnownShape(array $overrides): void
    {
        $allowed = array_keys(MollySettings::DEFAULTS);
        foreach (array_keys($overrides) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new RuntimeException('SETTINGS_UNKNOWN_KEY: Unsupported settings key "'.$key.'".');
            }
        }
    }
}

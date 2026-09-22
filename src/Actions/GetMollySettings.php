<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Settings\MollySettings;
use Sifrious\Molly\Settings\SettingsStore;

final class GetMollySettings
{
    public function __construct(private SettingsStore $store) {}

    /**
     * @return array{schema_version: int, defaults: array<string, mixed>, settings: array<string, mixed>, path: string}
     */
    public function handle(): array
    {
        $settings = $this->store->read();

        return [
            'schema_version' => MollySettings::SCHEMA_VERSION,
            'defaults' => MollySettings::DEFAULTS,
            'settings' => $settings->toArray(),
            'path' => $this->store->path(),
        ];
    }
}

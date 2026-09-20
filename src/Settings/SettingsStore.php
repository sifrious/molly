<?php

namespace Sifrious\Molly\Settings;

use Illuminate\Support\Facades\File;
use RuntimeException;

/** Persist global Molly settings under MOLLY_HOME/settings.json. */
final class SettingsStore
{
    public function __construct(private ?string $home = null) {}

    public function home(): string
    {
        if ($this->home !== null) {
            return $this->home;
        }

        $env = getenv('MOLLY_HOME');
        if (is_string($env) && $env !== '') {
            return rtrim(str_replace('\\', '/', $env), '/');
        }

        $userHome = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return rtrim(str_replace('\\', '/', $userHome), '/').'/.molly';
    }

    public function path(): string
    {
        return $this->home().'/settings.json';
    }

    public function read(): MollySettings
    {
        $path = $this->path();
        if (! is_file($path)) {
            return MollySettings::defaults();
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('SETTINGS_INVALID: '.$path.' is not valid JSON.');
        }

        if (($payload['schema_version'] ?? null) !== MollySettings::SCHEMA_VERSION) {
            return MollySettings::defaults();
        }

        $values = $payload['settings'] ?? [];
        if (! is_array($values)) {
            return MollySettings::defaults();
        }

        return MollySettings::fromArray($values);
    }

    public function write(MollySettings $settings): void
    {
        File::ensureDirectoryExists($this->home(), 0700);
        $payload = [
            'schema_version' => MollySettings::SCHEMA_VERSION,
            'updated_at' => gmdate('c'),
            'settings' => $settings->toArray(),
        ];
        $mask = umask(0077);
        try {
            File::put(
                $this->path(),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
        } finally {
            umask($mask);
        }
    }
}

<?php

namespace Sifrious\Molly\Classification;

/**
 * The single authoritative Jev gate (MME-5633). Every planning, advice,
 * commit-review, HTTP, Livewire, Artisan, and MCP path asks this class
 * whether Jev may classify, and nothing else reads molly.jev.enabled.
 */
final class JevGate
{
    public const DISABLED = 'disabled';

    public const UNAVAILABLE = 'unavailable';

    public const UNCONFIGURED = 'unconfigured';

    public const READY = 'ready';

    public function __construct(private ChoiceClassifier $classifier) {}

    /**
     * MOLLY_JEV_ENABLED=true is the only way to open the gate.
     */
    public function enabled(): bool
    {
        return config('molly.jev.enabled', false) === true;
    }

    /**
     * Enabled, and the exact usable classification surface is installed.
     */
    public function available(): bool
    {
        return $this->enabled() && $this->classifier->available();
    }

    /**
     * Available, and Laravel AI holds a TypeSafe credential for the request.
     */
    public function ready(): bool
    {
        $key = config('ai.providers.typesafe.key');

        return $this->available() && is_string($key) && trim($key) !== '';
    }

    public function status(): string
    {
        return match (true) {
            ! $this->enabled() => self::DISABLED,
            ! $this->available() => self::UNAVAILABLE,
            ! $this->ready() => self::UNCONFIGURED,
            default => self::READY,
        };
    }

    /**
     * Machine-readable reason a request is not classified; null when ready.
     */
    public function reason(): ?string
    {
        return match ($this->status()) {
            self::DISABLED => 'jev_disabled',
            self::UNAVAILABLE => 'capability_missing',
            self::UNCONFIGURED => 'invalid_config',
            default => null,
        };
    }
}

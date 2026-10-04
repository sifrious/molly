<?php

namespace Sifrious\Molly\Acceptance;

/**
 * Facts read from a compiled Bloom process and its plugin directory.
 * This is not a check outcome. A caller-supplied PASS record is not one of these facts.
 */
final readonly class BloomHostSnapshot
{
    public function __construct(
        public bool $running,
        public ?int $pid,
        public string $command,
        public ?string $pluginId,
        public ?int $apiVersion,
        public bool $enabled,
        public string $processSource,
        public string $pluginSource,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'running' => $this->running,
            'pid' => $this->pid,
            'command' => $this->command,
            'plugin_id' => $this->pluginId,
            'api_version' => $this->apiVersion,
            'enabled' => $this->enabled,
            'process_source' => $this->processSource,
            'plugin_source' => $this->pluginSource,
        ];
    }
}

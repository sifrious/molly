<?php

namespace Sifrious\Molly\Settings;

/**
 * Global Molly loop / graph settings with documented defaults.
 *
 * Persisted under MOLLY_HOME/settings.json. Changing these values must never
 * rewrite historical run effective_config snapshots.
 */
final class MollySettings
{
    public const SCHEMA_VERSION = 1;

    /**
     * Documented defaults for Bloom Settings and ResolveEffectiveRunConfig.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'graph_storage' => [
            'project_database' => '.molly/knowledge.sqlite',
            'exact_version_cache' => '~/.molly/graph-cache',
        ],
        'runtime' => [
            'agent' => 'ollama',
            'model' => null,
        ],
        'loop' => [
            'max_iterations' => 3,
            'timeout_seconds' => 180,
            'test_timeout_seconds' => 120,
            'retry_limit' => 3,
            'continuation' => 'retry_on_verification_failure',
        ],
        'stop_success' => [
            'stop_on_user_request' => true,
            'success_requires_pest' => true,
            'success_requires_tarpit' => true,
        ],
        'verification' => [
            'pest' => 'required',
            'tarpit' => 'required',
            'parallel_join' => 'required',
            'actions' => [
                'pest' => 'retry',
                'tarpit' => 'retry',
                'parallel_join' => 'retry',
            ],
        ],
        'knowledge_sources' => [
            'laravel_graph' => true,
            'dependency_graphs' => true,
            'project_graph' => true,
            'cloud_conversation_ingest' => false,
        ],
    ];

    /** @param  array<string, mixed>  $values */
    public function __construct(public readonly array $values) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULTS);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function fromArray(array $overrides): self
    {
        return new self(self::merge(self::DEFAULTS, $overrides));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && self::isAssoc($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** @param  array<mixed>  $array */
    private static function isAssoc(array $array): bool
    {
        return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
    }
}

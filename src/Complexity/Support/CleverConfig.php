<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The single place raw config() values are read and narrowed into real
 * types. Wrong types fall back to the shipped defaults. a config mistake
 * must never crash a dev tool's boot path.
 */
final class CleverConfig
{
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function enabled(): ?bool
    {
        $value = $this->config->get('molly-complexity.enabled');

        return is_bool($value) ? $value : null;
    }

    public function root(): string
    {
        $value = $this->config->get('molly-complexity.root');

        return is_string($value) && $value !== '' ? $value : $this->app->basePath();
    }

    public function reportPath(): string
    {
        $value = $this->config->get('molly-complexity.report.path');

        return is_string($value) && $value !== '' ? $value : $this->app->storagePath('molly/complexity/report.json');
    }

    /**
     * @return list<string>
     */
    public function probeClasses(): array
    {
        return $this->stringList('molly-complexity.probes');
    }

    /**
     * @return list<string>
     */
    public function ownedPaths(): array
    {
        return $this->stringList('molly-complexity.owned_diff.paths', ['app', 'bootstrap', 'config', 'database', 'routes', 'resources/js']);
    }

    /**
     * @return list<string>
     */
    public function ownedExtensions(): array
    {
        return $this->stringList('molly-complexity.owned_diff.extensions', ['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'json']);
    }

    /**
     * @return list<string>
     */
    public function weldPaths(): array
    {
        return $this->stringList('molly-complexity.welds.paths', ['app']);
    }

    /**
     * @return list<string>
     */
    public function extraFacades(): array
    {
        return $this->stringList('molly-complexity.welds.facades');
    }

    public function maxSites(): int
    {
        return $this->positiveInt('molly-complexity.welds.max_sites', 200);
    }

    public function lonelyMinLines(): int
    {
        return $this->positiveInt('molly-complexity.lonely.min_lines', 30);
    }

    public function lonelyLimit(): int
    {
        return $this->positiveInt('molly-complexity.lonely.limit', 10);
    }

    public function churnSince(): ?string
    {
        $value = $this->config->get('molly-complexity.churn.since');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function churnLimit(): int
    {
        return $this->positiveInt('molly-complexity.churn.limit', 20);
    }

    /**
     * @return list<string>
     */
    public function excludeDirs(): array
    {
        return $this->stringList('molly-complexity.exclude');
    }

    public function appName(): string
    {
        $value = $this->config->get('app.name');

        return is_string($value) && $value !== '' ? $value : 'Laravel';
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function stringList(string $key, array $default = []): array
    {
        $value = $this->config->get($key);

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = $this->config->get($key);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}

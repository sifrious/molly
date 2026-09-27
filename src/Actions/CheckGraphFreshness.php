<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\ComposerLock;
use Sifrious\Molly\Knowledge\GraphManifest;
use Sifrious\Molly\Workspace\ObserveCheckout;
use Throwable;

/**
 * Report whether the graphs in `.molly/graphs/manifest.json` still match the checkout.
 *
 * Reads only: it never initializes Git, writes the manifest, or rebuilds a graph.
 */
final class CheckGraphFreshness
{
    /** Recorded as the revision when the project is not a Git checkout. */
    public const NO_GIT = 'no-git';

    public function __construct(
        private ComposerLock $lock,
        private ObserveCheckout $observe,
    ) {}

    public function revision(string $root): string
    {
        return $this->observe->head($root) ?? self::NO_GIT;
    }

    /**
     * @param  list<string>|null  $unitIds
     * @return array<string, mixed>
     */
    public function handle(string $root, ?array $unitIds = null): array
    {
        try {
            $manifest = GraphManifest::load($root);
        } catch (Throwable $exception) {
            return [
                'status' => 'invalid',
                'stale' => null,
                'reasons' => [],
                'units' => [],
                'error' => $exception->getMessage(),
                'fix' => 'php artisan molly:graphs-bootstrap',
            ];
        }

        try {
            $lock = $this->lock->read($root);
        } catch (Throwable) {
            $lock = ['packages' => [], 'lock_hash' => null];
        }

        return $manifest->freshness($this->revision($root), $lock['lock_hash'], $lock['packages'], $unitIds);
    }
}

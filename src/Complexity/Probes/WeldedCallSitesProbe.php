<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

use Sifrious\Molly\Complexity\Support\CleverConfig;
use Sifrious\Molly\Complexity\Support\SourceFiles;
use Sifrious\Molly\Complexity\Support\WeldScanner;

final class WeldedCallSitesProbe extends BaseProbe
{
    public function __construct(
        private readonly CleverConfig $config,
        private readonly SourceFiles $files,
        private readonly WeldScanner $scanner,
    ) {}

    public function key(): string
    {
        return 'c2';
    }

    public function name(): string
    {
        return 'The welded call sites';
    }

    public function prints(): string
    {
        return 'Counts calls to constructors with literal class names and static method calls matched by the printed grep patterns. The report separates recognized facade calls from other static calls.';
    }

    public function handVerify(): string
    {
        $paths = implode(' ', $this->config->weldPaths());

        return <<<CMD
        grep -rE --include='*.php' -o 'new [A-Z][A-Za-z0-9_\\\\]*\\(' {$paths} \\
          | wc -l | xargs printf 'welded new: %s\\n'
        grep -rE --include='*.php' -o '\\b[A-Z][A-Za-z0-9_]*::[a-zA-Z_]+\\(' {$paths} \\
          | grep -vE '(self|static|parent)::' | wc -l | xargs printf 'bare static calls: %s\\n'
        CMD;
    }

    public function pairsWith(): string
    {
        return 'Constructor calls, static calls, and recognized facade calls.';
    }

    /**
     * @return list<string>
     */
    public function caveats(): array
    {
        return [
            'The constructor pattern excludes new static, variable class names, and fully qualified names with a leading backslash. Review the reported calls before deciding whether dependency injection would help.',
            'Laravel facades resolve services from the container. Facade calls remain in the static-call total and have a separate count. Eloquent static calls remain candidates unless recognized as facades.',
        ];
    }

    protected function execute(): ProbeResult
    {
        $paths = $this->config->weldPaths();
        $found = $this->files->files($paths, ['php']);

        if ($found['files'] === [] && count($found['missing']) === count($paths)) {
            return $this->skipped('None of the configured paths exist.');
        }

        $extraFacades = $this->config->extraFacades();
        $maxSites = $this->config->maxSites();

        $weldedNewTotal = 0;
        $bareStaticTotal = 0;
        $facadeCount = 0;
        $filesScanned = 0;
        $unreadable = 0;
        $weldedNewSites = [];
        $bareStaticSites = [];
        $truncated = false;

        foreach ($found['files'] as $absolute) {
            $contents = $this->files->read($absolute);

            if ($contents === null) {
                $unreadable++;

                continue;
            }

            $filesScanned++;
            $relative = $this->files->relative($absolute);
            $scan = $this->scanner->scan($contents, $extraFacades);

            $weldedNewTotal += count($scan['welded_new']);
            $bareStaticTotal += count($scan['bare_static']);

            foreach ($scan['welded_new'] as $site) {
                if (count($weldedNewSites) < $maxSites) {
                    $weldedNewSites[] = ['file' => $relative, ...$site];
                } else {
                    $truncated = true;
                }
            }

            foreach ($scan['bare_static'] as $site) {
                if ($site['kind'] === 'facade') {
                    $facadeCount++;
                }

                if (count($bareStaticSites) < $maxSites) {
                    $bareStaticSites[] = ['file' => $relative, ...$site];
                } else {
                    $truncated = true;
                }
            }
        }

        $warnings = array_map(
            static fn (string $path): string => sprintf('Configured path does not exist: %s', $path),
            $found['missing'],
        );

        if ($unreadable > 0) {
            $warnings[] = sprintf('%d unreadable or binary files skipped', $unreadable);
        }

        return $this->ok(
            metrics: [
                'welded_new' => $weldedNewTotal,
                'bare_static_total' => $bareStaticTotal,
                'bare_static_facades' => $facadeCount,
                'bare_static_candidates' => $bareStaticTotal - $facadeCount,
                'files_scanned' => $filesScanned,
                'welded_new_sites' => $weldedNewSites,
                'bare_static_sites' => $bareStaticSites,
                'sites_truncated' => $truncated,
            ],
            headline: sprintf('welded new: %d / bare static calls: %d', $weldedNewTotal, $bareStaticTotal),
            notes: [
                'The grep pattern misses constructor calls with a leading backslash, such as new \\App\\Foo(. The probe uses the same pattern.',
                'Matches inside comments and strings are counted; grep counts them too.',
                'The facade split is presented alongside the grep totals, never subtracted from them.',
            ],
            warnings: $warnings,
        );
    }
}

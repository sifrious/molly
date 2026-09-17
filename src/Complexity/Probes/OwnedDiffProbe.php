<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

use Sifrious\Molly\Complexity\Support\CleverConfig;
use Sifrious\Molly\Complexity\Support\LineClassifier;
use Sifrious\Molly\Complexity\Support\SourceFiles;

final class OwnedDiffProbe extends BaseProbe
{
    private const string CLASSIFIER_NOTE = 'Line classification uses text patterns rather than a PHP parser. Comment markers in strings and heredocs can change the counts. The classifier treats PHP 8 attributes as comments to match cloc. Counts can differ from cloc.';

    public function __construct(
        private readonly CleverConfig $config,
        private readonly SourceFiles $files,
        private readonly LineClassifier $classifier,
    ) {}

    public function key(): string
    {
        return 'c1';
    }

    public function name(): string
    {
        return 'The owned diff';
    }

    public function prints(): string
    {
        return 'Counts code, comment, and blank lines in the configured application paths. The report includes file totals and breakdowns by path and extension.';
    }

    public function handVerify(): string
    {
        $paths = implode(' ', $this->config->ownedPaths());

        return <<<CMD
        cloc --json {$paths} \\
          | php -r '\$s=json_decode(stream_get_contents(STDIN))->SUM;
              printf("owned diff: %d lines across %d files\\n",\$s->code,\$s->nFiles);'
        CMD;
    }

    public function pairsWith(): string
    {
        return 'Code lines and file counts by path and extension.';
    }

    /**
     * @return list<string>
     */
    public function caveats(): array
    {
        return [
            'Compare the count with a fresh Laravel application using the same directory list to estimate lines added beyond the application skeleton.',
            'Set molly-complexity.owned_diff.paths to the directories that contain application code. Line counts do not establish design quality.',
        ];
    }

    protected function execute(): ProbeResult
    {
        $paths = $this->config->ownedPaths();
        $found = $this->files->files($paths, $this->config->ownedExtensions());

        if ($found['files'] === [] && count($found['missing']) === count($paths)) {
            return $this->skipped('None of the configured paths exist.');
        }

        $totals = ['code' => 0, 'comment' => 0, 'blank' => 0];
        $fileCount = 0;
        $unreadable = 0;
        $byPath = [];
        $byExtension = [];

        foreach ($found['files'] as $absolute) {
            $contents = $this->files->read($absolute);

            if ($contents === null) {
                $unreadable++;

                continue;
            }

            $relative = $this->files->relative($absolute);
            $extension = $this->files->extensionOf($absolute);
            $counts = $this->classifier->count($contents, $extension);

            $fileCount++;
            $totals['code'] += $counts['code'];
            $totals['comment'] += $counts['comment'];
            $totals['blank'] += $counts['blank'];

            $configured = $this->configuredPathFor($relative, $paths);
            $byPath[$configured] ??= ['code_lines' => 0, 'files' => 0];
            $byPath[$configured]['code_lines'] += $counts['code'];
            $byPath[$configured]['files']++;

            $byExtension[$extension] ??= ['code_lines' => 0, 'files' => 0];
            $byExtension[$extension]['code_lines'] += $counts['code'];
            $byExtension[$extension]['files']++;
        }

        uasort($byExtension, static fn (array $a, array $b): int => $b['code_lines'] <=> $a['code_lines']);

        $warnings = array_map(
            static fn (string $path): string => sprintf('Configured path does not exist: %s', $path),
            $found['missing'],
        );

        if ($unreadable > 0) {
            $warnings[] = sprintf('%d unreadable or binary files skipped', $unreadable);
        }

        return $this->ok(
            metrics: [
                'code_lines' => $totals['code'],
                'comment_lines' => $totals['comment'],
                'blank_lines' => $totals['blank'],
                'files' => $fileCount,
                'by_path' => $byPath,
                'by_extension' => $byExtension,
                'paths' => $paths,
            ],
            headline: sprintf('owned diff: %d lines across %d files', $totals['code'], $fileCount),
            notes: [self::CLASSIFIER_NOTE],
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $paths
     */
    private function configuredPathFor(string $relative, array $paths): string
    {
        foreach ($paths as $path) {
            if ($relative === $path || str_starts_with($relative, $path.'/')) {
                return $path;
            }
        }

        return dirname($relative);
    }
}

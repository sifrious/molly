<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

use Sifrious\Molly\Complexity\Support\CleverConfig;
use Sifrious\Molly\Complexity\Support\Git;
use Sifrious\Molly\Complexity\Support\GitStatus;
use Sifrious\Molly\Complexity\Support\LineClassifier;
use Sifrious\Molly\Complexity\Support\NameOnlyLogParser;
use Sifrious\Molly\Complexity\Support\SourceFiles;

final class HotspotsProbe extends BaseProbe
{
    private const string LOC_AXIS_DEFENSE = 'Code lines measure current file size. The probe does not calculate cyclomatic complexity.';

    public function __construct(
        private readonly CleverConfig $config,
        private readonly Git $git,
        private readonly NameOnlyLogParser $parser,
        private readonly SourceFiles $files,
        private readonly LineClassifier $classifier,
    ) {}

    public function key(): string
    {
        return 'c4';
    }

    public function name(): string
    {
        return 'The hotspots';
    }

    public function prints(): string
    {
        return 'Lists commit counts alongside current code lines for frequently changed PHP files in the configured application paths. The report keeps both measurements separate.';
    }

    public function handVerify(): string
    {
        $since = $this->config->churnSince();
        $sinceFlag = $since === null ? '' : sprintf(" --since='%s'", $since);
        $limit = $this->config->churnLimit();

        return <<<CMD
        git log --no-merges{$sinceFlag} --pretty=format: --name-only -- '*.php' \\
          | awk 'NF {ch[\$0]++} END {for (f in ch) print ch[f], f}' \\
          | sort -rn | head -{$limit}
        CMD;
    }

    public function pairsWith(): string
    {
        return 'Commit counts and current code lines by file.';
    }

    /**
     * @return list<string>
     */
    public function caveats(): array
    {
        return [
            'File size and change frequency describe different properties. The report does not multiply the measurements or produce a complexity score.',
            'Churn counts commits that touch a path, not the number of changed lines. The scan does not follow files across renames.',
            'Current line counts describe the working tree. Commit counts describe the configured history period, including commits made before a file became smaller.',
        ];
    }

    protected function execute(): ProbeResult
    {
        $status = $this->git->preflight();

        if ($status !== GitStatus::Ok) {
            return $this->skipped((string) $status->skipReason());
        }

        $since = $this->config->churnSince();

        $args = ['--pretty=format:@%H', '--name-only', '--', '*.php'];

        if ($since !== null) {
            array_unshift($args, '--since='.$since);
        }

        $output = $this->git->log($args);

        if ($output === null) {
            return $this->error('Git log failed after the repository checks passed.');
        }

        $aggregate = $this->parser->aggregate($output);
        $ownedPaths = $this->config->ownedPaths();
        $limit = $this->config->churnLimit();
        $root = rtrim($this->config->root(), '/\\');

        $touched = [];

        foreach ($aggregate['perFile'] as $path => $entry) {
            if ($this->withinOwnedPaths($path, $ownedPaths)) {
                $touched[$path] = $entry['touches'];
            }
        }

        arsort($touched);

        $points = [];
        $missingOnDisk = 0;

        foreach ($touched as $path => $churn) {
            if (count($points) >= $limit) {
                break;
            }

            $contents = $this->files->read($root.'/'.$path);

            if ($contents === null) {
                $missingOnDisk++;

                continue;
            }

            $points[] = [
                'path' => $path,
                'churn' => $churn,
                'code_lines' => $this->classifier->count($contents, $this->files->extensionOf($path))['code'],
            ];
        }

        $warnings = $this->git->isShallow()
            ? ['The shallow clone contains partial history. Commit and author counts may omit earlier changes.']
            : [];

        return $this->ok(
            metrics: [
                'since' => $since,
                'commits_scanned' => $aggregate['commits'],
                'files_touched' => count($aggregate['perFile']),
                'points' => $points,
                'files_missing_on_disk' => $missingOnDisk,
                'limit' => $limit,
                'loc_axis_defense' => self::LOC_AXIS_DEFENSE,
            ],
            headline: sprintf(
                'hotspots: churn x lines for the %d most-churned php files since %s',
                count($points),
                $since ?? 'the beginning of history',
            ),
            notes: [
                'The probe includes only files within molly-complexity.owned_diff.paths. The shell command lists PHP files throughout the repository.',
                'Runs git with -c core.quotepath=off so non-ASCII paths arrive unescaped; the one-liner does not pass that flag.',
            ],
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $ownedPaths
     */
    private function withinOwnedPaths(string $path, array $ownedPaths): bool
    {
        foreach ($ownedPaths as $owned) {
            if ($path === $owned || str_starts_with($path, $owned.'/')) {
                return true;
            }
        }

        return false;
    }
}

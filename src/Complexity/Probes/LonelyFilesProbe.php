<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

use Sifrious\Molly\Complexity\Support\CleverConfig;
use Sifrious\Molly\Complexity\Support\Git;
use Sifrious\Molly\Complexity\Support\GitStatus;
use Sifrious\Molly\Complexity\Support\LineClassifier;
use Sifrious\Molly\Complexity\Support\NameOnlyLogParser;
use Sifrious\Molly\Complexity\Support\SourceFiles;

final class LonelyFilesProbe extends BaseProbe
{
    public function __construct(
        private readonly CleverConfig $config,
        private readonly Git $git,
        private readonly NameOnlyLogParser $parser,
        private readonly SourceFiles $files,
        private readonly LineClassifier $classifier,
    ) {}

    public function key(): string
    {
        return 'c3';
    }

    public function name(): string
    {
        return 'The lonely files';
    }

    public function prints(): string
    {
        return 'Lists PHP files with exactly one recorded author, ordered by the number of commits that changed each file. The configured minimum line count and list limit apply.';
    }

    public function handVerify(): string
    {
        return <<<'CMD'
        git log --no-merges --pretty=format:'@%an' --name-only -- '*.php' \
          | awk '/^@/ {au=substr($0,2); next}
                 NF {ch[$0]++; if (!seen[$0","au]++) auth[$0]++}
                 END {for (f in ch) if (auth[f]==1) print ch[f], f}' \
          | sort -rn | head
        CMD;
    }

    public function pairsWith(): string
    {
        return 'Commit counts and current code lines for files with one recorded author.';
    }

    /**
     * @return list<string>
     */
    public function caveats(): array
    {
        return [
            'One author can mean a new file or a small change history. The minimum line count excludes small files but does not establish a maintenance problem.',
            'Git history must be available in the measured workspace. Merge commits are excluded.',
        ];
    }

    protected function execute(): ProbeResult
    {
        $status = $this->git->preflight();

        if ($status !== GitStatus::Ok) {
            return $this->skipped((string) $status->skipReason());
        }

        $output = $this->git->log(['--pretty=format:@%an', '--name-only', '--', '*.php']);

        if ($output === null) {
            return $this->error('Git log failed after the repository checks passed.');
        }

        $aggregate = $this->parser->aggregate($output);
        $minLines = $this->config->lonelyMinLines();
        $limit = $this->config->lonelyLimit();
        $root = rtrim($this->config->root(), '/\\');

        $lonely = [];
        $lonelyTotal = 0;
        $filteredMissing = 0;
        $filteredTiny = 0;

        foreach ($aggregate['perFile'] as $path => $entry) {
            if (count($entry['authors']) !== 1) {
                continue;
            }

            $lonelyTotal++;
            $absolute = $root.'/'.$path;
            $contents = $this->files->read($absolute);

            if ($contents === null) {
                $filteredMissing++;

                continue;
            }

            $codeLines = $this->classifier->count($contents, $this->files->extensionOf($path))['code'];

            if ($codeLines < $minLines) {
                $filteredTiny++;

                continue;
            }

            $lonely[] = [
                'path' => $path,
                'commits' => $entry['touches'],
                'author' => (string) array_key_first($entry['authors']),
                'code_lines' => $codeLines,
            ];
        }

        usort($lonely, static fn (array $a, array $b): int => [$b['commits'], $b['code_lines'], $a['path']] <=> [$a['commits'], $a['code_lines'], $b['path']]);

        $warnings = $this->git->isShallow()
            ? ['The shallow clone contains partial history. Commit and author counts may omit earlier changes.']
            : [];

        return $this->ok(
            metrics: [
                'top' => array_slice($lonely, 0, $limit),
                'files_with_history' => count($aggregate['perFile']),
                'lonely_total' => $lonelyTotal,
                'filtered_missing' => $filteredMissing,
                'filtered_tiny' => $filteredTiny,
                'min_lines' => $minLines,
                'limit' => $limit,
            ],
            headline: sprintf(
                'lonely files: %d of %d churned php files have exactly one author',
                $lonelyTotal,
                count($aggregate['perFile']),
            ),
            notes: [
                'The shell command lists up to ten files without checking current size. The probe applies the configured list limit and excludes missing, unreadable, binary, and undersized files.',
                'Author names use the exact %an value from Git. Different spellings count as different authors in both the probe and shell command.',
                'Runs git with -c core.quotepath=off so non-ASCII paths arrive unescaped; the one-liner does not pass that flag.',
            ],
            warnings: $warnings,
        );
    }
}

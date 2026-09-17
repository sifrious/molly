<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ReviewCommit;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyReviewCommitCommand extends Command
{
    protected $signature = 'molly:review-commit {ref? : Commit to review, defaults to HEAD} {--staged : Review staged PHP changes instead of a commit} {--workspace= : Git workspace directory} {--json : Print structured evidence}';

    protected $description = 'Check a PHP diff and optionally ask Jev to evaluate unnecessary complexity';

    public function handle(ReviewCommit $review): int
    {
        try {
            if ($this->option('staged') && $this->argument('ref') !== null) {
                throw new \InvalidArgumentException('Choose --staged or a commit reference.');
            }
            $result = $review->handle((string) ($this->option('workspace') ?: base_path()), $this->argument('ref') ?? 'HEAD', (bool) $this->option('staged'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                table(['Check', 'Result'], [
                    ['PHP diff', $result['diff_bytes'].' bytes'],
                    ['Git whitespace check', $result['diff_check']['status']],
                    ['Jev evaluation', $result['evaluation']['status'].' / '.$result['evaluation']['reason']],
                    ['Suggested action', $result['evaluation']['next_action'] ?? 'Not evaluated'],
                    ['Confidence', $result['evaluation']['confidence'] === null ? 'Unavailable' : (string) $result['evaluation']['confidence']],
                    ['Tests', 'Not run by this command'],
                ]);
                foreach ($result['citations'] as $source) {
                    note($source['title'].' / '.$source['url'].' / revision '.$source['revision']);
                }
                note('A semantic review does not prove that the change works. Run the relevant tests before committing.');
            }
            $evaluation = $result['evaluation'];

            return $result['diff_check']['status'] === 'passed'
                && (in_array($evaluation['status'], ['disabled', 'not_applicable'], true)
                    || ($evaluation['status'] === 'evaluated' && $evaluation['next_action'] === 'continue'))
                ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}

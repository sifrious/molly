<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\PublishGitHubIssueStatus;
use Throwable;

use function Laravel\Prompts\note;

class MollyCommentCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:comment {task : Saved task name or ID} {--approve : Confirm posting or updating the GitHub status comment} {--close : Include closing language after required checks pass} {--json : Print JSON only}';

    protected $description = 'Post a concise GitHub issue comment after explicit approval';

    public function handle(PublishGitHubIssueStatus $publish): int
    {
        try {
            $result = $publish->handle((string) $this->argument('task'), (bool) $this->option('approve'), (bool) $this->option('close'));
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                note(($result['updated'] ? 'Updated' : 'Unchanged').' GitHub comment '.$result['comment_id'].'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}

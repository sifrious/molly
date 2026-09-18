<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\PublishGitHubIssueStatus;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyCommentCommand extends Command
{
    protected $signature = 'molly:comment {task : Saved task name or ID} {--approve : Confirm posting or updating the GitHub status comment} {--close : Include closing language after required checks pass} {--json : Print JSON only}';

    protected $description = 'Post a concise GitHub issue comment after explicit approval';

    public function handle(PublishGitHubIssueStatus $publish): int
    {
        try {
            $result = $publish->handle((string) $this->argument('task'), (bool) $this->option('approve'), (bool) $this->option('close'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note(($result['updated'] ? 'Updated' : 'Unchanged').' GitHub comment '.$result['comment_id'].'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}

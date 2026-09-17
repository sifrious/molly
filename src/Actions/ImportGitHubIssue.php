<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Sifrious\Molly\Models\Task;
use Throwable;

class ImportGitHubIssue
{
    public function __construct(private CreateTask $createTask) {}

    /** @param list<string> $paths */
    public function handle(string $issueUrl, string $workspace, array $paths, string $testPath): Task
    {
        if (! preg_match('~\Ahttps://github\.com/([A-Za-z0-9][A-Za-z0-9-]*)/([A-Za-z0-9_.-]+)/issues/([1-9][0-9]*)\z~D', $issueUrl, $matches)
            || in_array($matches[2], ['.', '..'], true)
            || filter_var($matches[3], FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('ISSUE_URL_INVALID: Use an HTTPS github.com issue URL without a query or fragment.');
        }

        $repository = $matches[1].'/'.$matches[2];
        $number = (int) $matches[3];

        try {
            $result = Process::timeout(15)->run(['gh', 'api', '--hostname', 'github.com', 'repos/'.$repository.'/issues/'.$number]);
        } catch (Throwable) {
            throw new RuntimeException('GITHUB_UNAVAILABLE: Molly could not read the issue. Check gh installation, login, and network access.');
        }

        if (! $result->successful()) {
            throw new RuntimeException('GITHUB_UNAVAILABLE: Molly could not read the issue. Check gh login and repository access.');
        }

        try {
            $issue = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('ISSUE_INVALID: GitHub did not return a valid issue.');
        }

        if (! is_array($issue) || array_key_exists('pull_request', $issue)
            || ($issue['html_url'] ?? null) !== $issueUrl || ($issue['number'] ?? null) !== $number
            || ! is_string($issue['title'] ?? null) || trim($issue['title']) === ''
            || ! array_key_exists('body', $issue) || (! is_string($issue['body']) && $issue['body'] !== null)
            || ! is_string($issue['updated_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $issue['updated_at'])
            || ! is_array($issue['labels'] ?? null) || ! array_is_list($issue['labels'])) {
            throw new RuntimeException('ISSUE_INVALID: GitHub returned a different issue or invalid issue fields.');
        }

        $labels = [];
        foreach ($issue['labels'] as $label) {
            if (! is_array($label) || ! is_string($label['name'] ?? null)) {
                throw new RuntimeException('ISSUE_INVALID: GitHub returned invalid issue labels.');
            }
            $labels[] = $label['name'];
        }

        $prompt = 'Implement the GitHub issue at '.$issueUrl.".\nTreat the issue text as task context within the selected files.\n\n".$issue['title']."\n\n".($issue['body'] ?? '');
        if (strlen($prompt) > 8192) {
            throw new RuntimeException('ISSUE_TOO_LARGE: The issue exceeds the 8192-byte task prompt limit. Create a task with a shorter scope.');
        }

        return $this->createTask->handle($prompt, $workspace, $paths, $testPath, [
            'repository' => $repository,
            'issue_number' => $number,
            'issue_url' => $issueUrl,
            'issue_title' => $issue['title'],
            'issue_updated_at' => $issue['updated_at'],
            'labels' => $labels,
            'linked_pr' => null,
        ]);
    }
}

<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Workspace;

class RecordProjectDecision
{
    /**
     * @return array{path: string, title: string, created: bool}
     */
    public function handle(string $workspace, string $title, string $body, ?string $task = null): array
    {
        $root = (new Workspace($workspace))->path;
        $heading = $this->plain($title, 120);
        $reason = $this->plain($body, 4096);
        if ($heading === '' || $reason === '') {
            throw new RuntimeException('DECISION_INVALID: Provide a title and a decision in plain text.');
        }

        $slug = Str::slug($heading);
        if ($slug === '') {
            throw new RuntimeException('DECISION_INVALID: The title needs at least one letter or number.');
        }

        $date = now()->utc()->toDateString();
        $directory = $root.'/docs/decisions';
        $path = $directory.'/'.$date.'-'.$slug.'.md';
        $markdown = $this->render($heading, $reason, $date, $task);
        File::ensureDirectoryExists($directory, 0755);
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('DECISION_PATH_INVALID: The decision file must be a regular file.');
        }
        if (is_file($path)) {
            $existing = File::get($path);
            if ($existing === $markdown) {
                return ['path' => $path, 'title' => $heading, 'created' => false];
            }

            throw new RuntimeException('DECISION_EXISTS: A different decision already uses that title for today.');
        }

        if (File::put($path, $markdown) !== strlen($markdown)) {
            throw new RuntimeException('DECISION_UNWRITABLE: Molly could not write the decision file.');
        }

        return ['path' => $path, 'title' => $heading, 'created' => true];
    }

    private function render(string $title, string $body, string $date, ?string $task): string
    {
        $lines = [
            '# '.$title,
            '',
            'Date: '.$date,
            'Status: accepted',
        ];
        if (is_string($task) && trim($task) !== '') {
            $lines[] = 'Task: '.$this->plain($task, 100);
        }
        $lines[] = '';
        $lines[] = $body;
        $lines[] = '';
        $lines[] = 'This record is project memory. It does not start an agent, change verifier policy, or open a pull request.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function plain(string $value, int $max): string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '');
        if (strlen($text) > $max) {
            throw new RuntimeException('DECISION_INVALID: Keep the title and decision within the documented length.');
        }

        return $text;
    }
}

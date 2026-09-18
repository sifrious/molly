<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Workspace;
use Throwable;

class CaptureComponentPreview
{
    /**
     * @param  array<string, ?string>  $contents
     * @return array{status: string, reason?: string, viewport?: string, fixture?: string, renderer?: string, commit?: ?string, digest?: string, path?: string}
     */
    public function handle(string $workspace, array $contents, string $phase): array
    {
        $command = config('molly.preview.command');
        $url = config('molly.preview.url');
        $viewport = (string) config('molly.preview.viewport', '1280x720');
        if ((! is_string($command) || trim($command) === '') && (! is_string($url) || trim($url) === '')) {
            return ['status' => 'unavailable', 'reason' => 'No local preview renderer is configured.'];
        }

        $components = array_filter(
            $contents,
            fn (?string $content, string $path): bool => is_string($content) && $this->previewable($path),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($components === []) {
            return ['status' => 'unavailable', 'reason' => 'No recognized Blade or Livewire source was selected for a preview.'];
        }

        $root = (new Workspace($workspace))->path;
        $directory = $root.'/.molly/previews';
        File::ensureDirectoryExists($directory, 0700);
        $fixture = $directory.'/'.$phase.'-'.bin2hex(random_bytes(8)).'.html';
        $image = $directory.'/'.$phase.'-'.bin2hex(random_bytes(8)).'.png';
        File::put($fixture, $this->html($components, $phase, $viewport));

        if (! is_string($command) || trim($command) === '') {
            return ['status' => 'unavailable', 'reason' => 'A preview URL is recorded, but no local renderer command is configured.'];
        }

        $argv = array_values(array_filter(array_map(
            fn (string $part): string => str_replace(['{input}', '{output}', '{url}', '{viewport}'], [$fixture, $image, (string) $url, $viewport], $part),
            preg_split('/\s+/', trim($command)) ?: [],
        ), fn (string $part): bool => $part !== ''));
        try {
            $result = Process::timeout(30)->path($root)->run($argv);
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason' => 'The configured preview renderer could not be started.'];
        }
        if (! $result->successful() || ! is_file($image)) {
            return ['status' => 'unavailable', 'reason' => 'The configured preview renderer did not produce an image.'];
        }

        return [
            'status' => 'captured',
            'viewport' => $viewport,
            'fixture' => $phase,
            'renderer' => $argv[0],
            'commit' => $this->commit($root),
            'digest' => hash_file('sha256', $image),
            'path' => $image,
        ];
    }

    private function previewable(string $path): bool
    {
        return str_ends_with($path, '.blade.php')
            || str_starts_with($path, 'app/Livewire/')
            || str_starts_with($path, 'app/View/Components/');
    }

    /**
     * @param  array<string, string>  $components
     */
    private function html(array $components, string $phase, string $viewport): string
    {
        $blocks = '';
        foreach ($components as $path => $content) {
            $blocks .= '<section data-path="'.htmlspecialchars($path, ENT_QUOTES).'"><h1>'.htmlspecialchars($path, ENT_QUOTES).'</h1><pre>'.htmlspecialchars($content, ENT_QUOTES).'</pre></section>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Molly '.$phase.' preview '.$viewport.'</title></head><body>'.$blocks.'</body></html>';
    }

    private function commit(string $workspace): ?string
    {
        try {
            $result = Process::timeout(5)->path($workspace)->run(['git', 'rev-parse', 'HEAD']);
        } catch (Throwable) {
            return null;
        }

        $sha = trim($result->output());

        return $result->successful() && preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1 ? $sha : null;
    }
}

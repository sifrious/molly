<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Knowledge\LivewireLayout;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

/**
 * Derive numbered acceptance criteria and the implementation files from a
 * plain-English story with the configured model, then save a test-authoring
 * task that carries them. Molly stops there: a human runs the authoring task,
 * approves the lock with the derived files, and starts the implementation.
 */
class CreateTaskFromStory
{
    public function __construct(private CreateTask $create, private AmpResponse $amp, private LocalOllama $ollama) {}

    /** @param  list<string>  $paths */
    public function handle(string $story, string $workspace, array $paths, string $testPath, ?string $nickname = null): Task
    {
        if (trim($story) === '' || strlen($story) > 4000 || ! mb_check_encoding($story, 'UTF-8')) {
            throw new RuntimeException('STORY_INVALID: Describe the story in 1 to 4000 UTF-8 bytes.');
        }
        $files = new Workspace($workspace);
        $files->taskPaths($paths, $testPath, true);
        if ($nickname !== null && trim($nickname) !== '') {
            Task::validateNickname($nickname);
        }

        $agent = AcceptanceWriter::make();
        $livewire = LivewireLayout::forWorkspace($files->path);
        $input = json_encode([
            'story' => $story,
            'test_path' => $testPath,
            'existing_files' => $files->sourceFiles(),
            'livewire' => $livewire->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->acquire($agent, $input);
        $criteria = $this->criteria($result);
        $scope = $this->scope($result, $files, $paths, $testPath, $livewire);
        $numbered = implode("\n", array_map(fn (string $criterion, int $index): string => ($index + 1).'. '.$criterion, $criteria, array_keys($criteria)));

        $prompt = 'Write the Pest acceptance test at '.$testPath." for the story below. Cover every numbered acceptance criterion with at least one Pest test that names its number. Assert against the application's routes, pages, and components that the implementation will add, so the test fails until the behavior exists.\n\n"
            ."Story:\n".$story."\n\nAcceptance criteria:\n".$numbered;

        $provenance = [
            'agent' => config('molly.agent', 'ollama'),
            'model' => config('molly.agent', 'ollama') === 'ollama' ? config('molly.model') : null,
            'prompt_digest' => hash('sha256', json_encode(['instructions' => $agent->instructions(), 'input' => $input], JSON_THROW_ON_ERROR)),
            'story_digest' => hash('sha256', $story),
            'derived_at' => now()->toIso8601String(),
        ];

        // The derived files stay out of the authoring task's paths: authoring writes only the test.
        return $this->create->handle($prompt, $workspace, $paths, $testPath, [
            'provider' => 'molly-story',
            'story' => $story,
            'acceptance' => [
                'criteria' => $criteria,
                'text' => $numbered,
                'provenance' => $provenance,
            ],
            'scope' => [...$scope, ...$this->packages($result, $files->path), 'provenance' => $provenance],
        ], nickname: $nickname, allowTestEdits: true);
    }

    /** @return array<string, mixed> */
    private function acquire(AcceptanceWriter $agent, string $input): array
    {
        if (config('molly.agent', 'ollama') === 'amp') {
            return $this->amp->prompt($agent, $input);
        }

        if (config('molly.agent', 'ollama') === 'ollama') {
            return $this->ollama->prompt($agent, $input);
        }

        throw new RuntimeException('AGENT_INVALID: Choose amp or ollama for molly.agent.');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function criteria(array $result): array
    {
        $validator = Validator::make($result, [
            'criteria' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'criteria.*' => ['required', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('ACCEPTANCE_INVALID: The model did not return 1 to 30 acceptance criteria of up to 500 characters.');
        }

        return $result['criteria'];
    }

    /**
     * Keep the model's implementation files that pass the workspace path rules.
     * Each dropped path is recorded with its reason instead of failing the story.
     * A Livewire component class in the other Livewire version's layout moves to
     * the installed layout, and each component class brings the view Livewire
     * renders for it. Both changes are recorded in adjusted.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $paths
     * @return array{files: list<string>, rejected: list<array{path: string, reason: string}>, adjusted: list<array{path: string, from: ?string, reason: string}>}
     */
    private function scope(array $result, Workspace $workspace, array $paths, string $testPath, LivewireLayout $livewire): array
    {
        $proposed = is_array($result['files'] ?? null) && array_is_list($result['files']) ? $result['files'] : [];
        $room = max(0, (int) config('molly.max_files', 8) - count($paths));
        $files = [];
        $rejected = [];
        $adjusted = [];
        $version = $livewire->installedVersion === null ? 'Livewire is not installed, so Molly uses the current layout' : 'Livewire '.$livewire->installedVersion.' is installed';
        foreach ($proposed as $path) {
            if (! is_string($path) || trim($path) === '' || strlen($path) > 255) {
                $rejected[] = ['path' => is_string($path) ? substr($path, 0, 255) : get_debug_type($path), 'reason' => 'PATH_INVALID: The model returned an empty, oversized, or non-text path.'];

                continue;
            }
            $path = trim($path);
            $relocated = $livewire->relocate($path);
            if ($relocated !== null) {
                $adjusted[] = ['path' => $relocated, 'from' => $path, 'reason' => 'LIVEWIRE_LAYOUT: '.$version.', which resolves components from '.$livewire->classNamespace.' in '.$livewire->classDirectory.'.'];
                $path = $relocated;
            }
            if (in_array($path, $files, true) || in_array($path, $paths, true)) {
                continue;
            }
            $reason = $workspace->implementationPathError($path, $testPath)
                ?? (count($files) >= $room ? 'FILES_INVALID: The task already has the maximum of '.config('molly.max_files', 8).' implementation files.' : null);
            if ($reason !== null) {
                $rejected[] = ['path' => $path, 'reason' => $reason];

                continue;
            }
            $files[] = $path;
        }

        foreach ([...$paths, ...$files] as $class) {
            $view = $livewire->viewFor($class);
            if ($view === null || in_array($view, [...$paths, ...$files], true)) {
                continue;
            }
            $reason = $workspace->implementationPathError($view, $testPath)
                ?? (count($files) >= $room ? 'FILES_INVALID: The task already has the maximum of '.config('molly.max_files', 8).' implementation files.' : null);
            if ($reason !== null) {
                $rejected[] = ['path' => $view, 'reason' => $reason];

                continue;
            }
            $files[] = $view;
            $adjusted[] = ['path' => $view, 'from' => null, 'reason' => 'LIVEWIRE_VIEW: Livewire renders this view for '.$class.' by convention.'];
        }

        if ($files === [] && $paths === []) {
            throw new RuntimeException('SCOPE_EMPTY: The model named no implementation file Molly may change'
                .($rejected === [] ? '.' : '. Rejected: '.implode('; ', array_map(fn (array $entry): string => $entry['path'].' ('.$entry['reason'].')', $rejected)).'.')
                .' Run molly:story again, or name the files with --file.');
        }

        return ['files' => $files, 'rejected' => $rejected, 'adjusted' => $adjusted];
    }

    /**
     * Compare the packages the model says the behavior needs with the
     * application's composer.json require list and composer.lock. Molly
     * reports what to install; it never runs Composer, and the
     * implementation run cannot add packages.
     *
     * @param  array<string, mixed>  $result
     * @return array{required_packages: list<array{name: string, status: string, command: string|null}>, rejected_packages: list<array{name: string, reason: string}>}
     */
    private function packages(array $result, string $workspace): array
    {
        $composer = $this->json($workspace.'/composer.json');
        $lock = $this->json($workspace.'/composer.lock');
        $lockNames = fn (string $key): array => array_column(is_array($lock[$key] ?? null) ? array_filter($lock[$key], is_array(...)) : [], 'name');
        $installed = array_map(strtolower(...), $lockNames('packages'));
        $installedDev = array_map(strtolower(...), $lockNames('packages-dev'));
        $require = array_map(strtolower(...), array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []));
        $requireDev = array_map(strtolower(...), array_keys(is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : []));

        $packages = [];
        $rejected = [];
        $proposed = is_array($result['required_packages'] ?? null) && array_is_list($result['required_packages']) ? $result['required_packages'] : [];
        foreach ($proposed as $name) {
            $name = is_string($name) ? strtolower(trim($name)) : '';
            if (preg_match('~\A[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z~', $name) !== 1) {
                $rejected[] = ['name' => substr(is_string($name) ? $name : '', 0, 255), 'reason' => 'PACKAGE_INVALID: Not a Composer package name in vendor/package form.'];

                continue;
            }
            if (in_array($name, array_column($packages, 'name'), true)) {
                continue;
            }
            $status = match (true) {
                in_array($name, $require, true) => 'required',
                in_array($name, $requireDev, true) || in_array($name, $installedDev, true) => 'dev_only',
                in_array($name, $installed, true) => 'transitive',
                default => 'missing',
            };
            $packages[] = ['name' => $name, 'status' => $status, 'command' => $status === 'required' ? null : 'composer require '.$name];
        }

        return ['required_packages' => $packages, 'rejected_packages' => $rejected];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}

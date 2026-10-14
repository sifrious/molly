<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Sifrious\Molly\Actions\ListMollyProjects;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Projects\MollyProject;

/**
 * Writes the host records molly:verify reads.
 *
 * A record is a fact this call just read: a listed project, a listed task,
 * the running Bloom process, or the installed plugin files. A fact that is
 * not there is left unwritten.
 */
final class HostObservationRecorder
{
    public function __construct(
        private ListMollyProjects $projects,
        private ListTasks $tasks,
        private BloomHostInspector $hosts,
        private PackageIdentityReader $packages,
    ) {}

    /**
     * @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>}
     */
    public function recordProject(string $projectRoot, string $reader): array
    {
        if ($reader !== 'plugin') {
            return $this->skip('project', 'READER_NOT_PLUGIN');
        }
        [$host, $candidate, $reason] = $this->provenance();
        if ($host === null || $candidate === null) {
            return $this->skip('project', $reason ?? 'HOST_NOT_OBSERVED');
        }
        $project = $this->listedProject($projectRoot);
        if (! $project instanceof MollyProject) {
            return $this->skip('project', 'PROJECT_NOT_LISTED');
        }
        $check = $project->source === 'new' ? 'M04.2' : 'M04.3';
        $actual = 'source '.$project->source;
        $path = $this->writeNative($projectRoot, $check, $check.' lists a project whose source is '.$project->source.'.', $actual, $candidate, [
            'project' => $project->toArray(),
        ]);

        return $this->recorded($check, $path);
    }

    /**
     * @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>}
     */
    public function recordTask(string $projectRoot, string $taskId, string $reader): array
    {
        if ($reader !== 'plugin') {
            return $this->skip('M04.12', 'READER_NOT_PLUGIN');
        }
        $task = $this->listedTask($projectRoot, $taskId);
        if (! $task instanceof Task) {
            return $this->skip('M04.12', $taskId === '' ? 'TASK_REQUIRED' : 'TASK_NOT_LISTED');
        }
        [$host, $candidate, $reason] = $this->provenance();
        if ($host === null || $candidate === null) {
            return $this->skip('M04.12', $reason ?? 'HOST_NOT_OBSERVED');
        }
        $path = $this->writeNative($projectRoot, 'M04.12', 'CLI task is in the list Bloom reads', $task->id, $candidate, [
            'task_id' => $task->id,
            'workspace' => $task->workspace,
        ]);
        $restart = $this->recordRestart($projectRoot, $task->id, 'plugin');
        $records = [['check' => 'M04.12', 'path' => $path]];
        foreach ($restart['records'] as $record) {
            $records[] = $record;
        }

        return $this->result($records, $restart['skipped']);
    }

    /**
     * @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>}
     */
    public function recordRestart(string $projectRoot, string $taskId, string $reader): array
    {
        if (! in_array($reader, ['plugin', 'cli'], true)) {
            return $this->skip('M04.16', 'READER_INVALID');
        }
        $task = $this->listedTask($projectRoot, $taskId);
        if (! $task instanceof Task) {
            return $this->skip('M04.16', $taskId === '' ? 'TASK_REQUIRED' : 'TASK_NOT_LISTED');
        }
        [$host, $candidate, $reason] = $this->provenance();
        if ($host === null || $candidate === null) {
            return $this->skip('M04.16', $reason ?? 'HOST_NOT_OBSERVED');
        }
        $path = $this->normalize($projectRoot).'/.molly/restart-state.json';
        $at = $this->observedAt();
        $read = [
            'task_id' => $task->id,
            'pid' => $host->pid,
            'source' => $reader,
            'observed_at' => $at,
        ];
        $reads = $this->priorReads($path, $candidate);
        $reads[] = $read;
        $pair = $this->pair($reads, $host->pid);
        $payload = [
            'process' => ['pid' => $host->pid],
            'host' => $host->command,
            'plugin' => $host->pluginId,
            'candidate' => $candidate,
            'observed_at' => $at,
            'reads' => $reads,
        ];
        if ($pair !== null) {
            $payload['before'] = $pair[0];
            $payload['after'] = $pair[1];
        }
        $this->writeJson($path, $payload);

        return $this->recorded('M04.16', $path);
    }

    /**
     * @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>}
     */
    public function recordDiagnostics(string $projectRoot): array
    {
        [$host, $candidate, $reason] = $this->provenance();
        if ($host === null || $candidate === null) {
            return $this->result([], [
                ['check' => 'M04.13', 'reason' => $reason ?? 'HOST_NOT_OBSERVED'],
                ['check' => 'M04.15', 'reason' => $reason ?? 'HOST_NOT_OBSERVED'],
            ]);
        }
        $records = [];
        $skipped = [];
        $incompatible = is_int($host->apiVersion) && $host->apiVersion !== 1 && $host->loaded === false;
        if ($incompatible) {
            $records[] = [
                'check' => 'M04.13',
                'path' => $this->writeDiagnostic($projectRoot, 'host-incompat.log', $host, $candidate, [
                    'loaded' => false,
                    'api_version' => $host->apiVersion,
                ]),
            ];
        } else {
            $skipped[] = ['check' => 'M04.13', 'reason' => 'INCOMPAT_NOT_OBSERVED'];
        }
        $assets = $this->assets($host->pluginSource);
        if ($assets['missing_bundle'] || $assets['missing_provider']) {
            $records[] = [
                'check' => 'M04.15',
                'path' => $this->writeDiagnostic($projectRoot, 'host-broken.log', $host, $candidate, $assets),
            ];
        } else {
            $skipped[] = ['check' => 'M04.15', 'reason' => 'ASSETS_PRESENT'];
        }

        return $this->result($records, $skipped);
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function writeNative(string $projectRoot, string $check, string $assertion, string $actual, string $candidate, array $observed): string
    {
        $name = $check.'-observed.json';
        $directory = $this->normalize($projectRoot).'/acceptance-native';
        $artifact = [
            'candidate_sha' => $candidate,
            'actual' => $actual,
            'observed_at' => $this->observedAt(),
            'observed' => $observed,
        ];
        $this->writeJson($directory.'/'.$name, $artifact);
        $record = $directory.'/'.$check.'.json';
        $this->writeJson($record, [
            'candidate_sha' => $candidate,
            'assertion' => $assertion,
            'expected' => $actual,
            'actual' => $actual,
            'source_artifact' => $name,
        ]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function writeDiagnostic(string $projectRoot, string $name, BloomHostSnapshot $host, string $candidate, array $fields): string
    {
        $path = $this->normalize($projectRoot).'/'.$name;
        $this->writeJson($path, [
            'process' => ['pid' => $host->pid],
            'host' => $host->command,
            'plugin' => $host->pluginId,
            'candidate' => $candidate,
            'observed_at' => $this->observedAt(),
            ...$fields,
        ]);

        return $path;
    }

    /**
     * @return array{0: ?BloomHostSnapshot, 1: ?string, 2: ?string}
     */
    private function provenance(): array
    {
        $candidate = $this->packages->read()->commit;
        if (! is_string($candidate) || preg_match('/\A[0-9a-f]{40}\z/', $candidate) !== 1) {
            return [null, null, 'CANDIDATE_UNVERIFIED'];
        }
        $host = $this->hosts->inspect();
        if (! $host->running || $host->ambiguous || ! is_int($host->pid) || $host->pid < 2 || $host->command === '') {
            return [null, null, 'HOST_NOT_OBSERVED'];
        }
        if ($host->pluginId !== 'sifrious.molly') {
            return [null, null, 'HOST_NOT_OBSERVED'];
        }

        return [$host, $candidate, null];
    }

    private function listedProject(string $path): ?MollyProject
    {
        $needle = $this->normalize($path);
        foreach ($this->projects->handle() as $project) {
            if ($this->normalize($project->path) === $needle) {
                return $project;
            }
        }

        return null;
    }

    private function listedTask(string $projectRoot, string $taskId): ?Task
    {
        if ($taskId === '') {
            return null;
        }
        $root = $this->normalize($projectRoot);
        foreach ($this->tasks->handle(100) as $task) {
            if ($this->normalize((string) $task->workspace) !== $root) {
                continue;
            }
            if ($task->id === $taskId || $task->nickname === $taskId) {
                return $task;
            }
        }

        return null;
    }

    /** @return array{missing_bundle: bool, missing_provider: bool} */
    private function assets(string $pluginJson): array
    {
        $directory = dirname($pluginJson);
        $binary = $directory.'/Surfaces.bundle/Contents/MacOS/MollySurfaces';
        $plist = $directory.'/Surfaces.bundle/Contents/Info.plist';
        $body = is_file($plist) ? (string) file_get_contents($plist) : '';

        return [
            'missing_bundle' => ! is_file($binary),
            'missing_provider' => $body === '' || ! str_contains($body, 'MollySurfaceProvider'),
        ];
    }

    /** @return list<array{task_id: string, pid: int, source: string, observed_at: string}> */
    private function priorReads(string $path, string $candidate): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ($decoded['candidate'] ?? null) !== $candidate || ! is_array($decoded['reads'] ?? null)) {
            return [];
        }
        $reads = [];
        foreach ($decoded['reads'] as $read) {
            $valid = $this->validRead($read);
            if ($valid !== null) {
                $reads[] = $valid;
            }
        }

        return $reads;
    }

    /** @return array{task_id: string, pid: int, source: string, observed_at: string}|null */
    private function validRead(mixed $read): ?array
    {
        if (! is_array($read)) {
            return null;
        }
        $task = $read['task_id'] ?? null;
        $pid = $read['pid'] ?? null;
        $source = $read['source'] ?? null;
        $at = $read['observed_at'] ?? null;
        if (! is_string($task) || $task === '' || ! is_int($pid) || $pid < 2) {
            return null;
        }
        if (! in_array($source, ['plugin', 'cli'], true) || ! is_string($at) || $at === '') {
            return null;
        }

        return ['task_id' => $task, 'pid' => $pid, 'source' => $source, 'observed_at' => $at];
    }

    /**
     * @param  list<array{task_id: string, pid: int, source: string, observed_at: string}>  $reads
     * @return array{0: array{task_id: string, pid: int, source: string, observed_at: string}, 1: array{task_id: string, pid: int, source: string, observed_at: string}}|null
     */
    private function pair(array $reads, int $currentPid): ?array
    {
        $matched = null;
        foreach ($reads as $left) {
            foreach ($reads as $right) {
                if ($left === $right || $left['task_id'] !== $right['task_id'] || $left['source'] === $right['source']) {
                    continue;
                }
                if ($left['pid'] === $right['pid'] || $left['observed_at'] === $right['observed_at']) {
                    continue;
                }
                $before = $left['observed_at'] < $right['observed_at'] ? $left : $right;
                $after = $before === $left ? $right : $left;
                if ($after['pid'] !== $currentPid) {
                    continue;
                }
                $matched = [$before, $after];
            }
        }

        return $matched;
    }

    private function observedAt(): string
    {
        $seconds = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $fraction = str_pad((string) (hrtime(true) % 1000000000), 9, '0', STR_PAD_LEFT);

        return $seconds->format('Y-m-d\TH:i:s').'.'.$fraction.'Z';
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $real = realpath($path);

        return $real !== false ? rtrim(str_replace('\\', '/', $real), '/') : $path;
    }

    /** @param  array<string, mixed>  $payload */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('HOST_RECORD_UNWRITABLE: '.$directory);
        }
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        if (file_put_contents($temporary, $encoded) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('HOST_RECORD_UNWRITABLE: '.$path);
        }
    }

    /**
     * @param  list<array{check: string, path: string}>  $records
     * @param  list<array{check: string, reason: string}>  $skipped
     * @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>}
     */
    private function result(array $records, array $skipped): array
    {
        return ['records' => $records, 'skipped' => $skipped];
    }

    /** @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>} */
    private function skip(string $check, string $reason): array
    {
        return $this->result([], [['check' => $check, 'reason' => $reason]]);
    }

    /** @return array{records: list<array{check: string, path: string}>, skipped: list<array{check: string, reason: string}>} */
    private function recorded(string $check, string $path): array
    {
        return $this->result([['check' => $check, 'path' => $path]], []);
    }
}

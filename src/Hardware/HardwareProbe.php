<?php

namespace Sifrious\Molly\Hardware;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Measures the host with read-only commands and loopback Ollama API calls.
 *
 * Every fact is either measured, with the command that produced it and the raw
 * value it returned, or unknown with a reason. The probe never estimates a value.
 */
class HardwareProbe
{
    /** @var array<string, array{ok: bool, output: string, reason: string}> */
    private array $runs = [];

    /** @return array<string, array<string, array<string, mixed>>> */
    public function facts(?string $destination = null): array
    {
        $this->runs = [];
        $platform = $this->platform();
        [$macos, $skip] = $this->scope($platform);

        return [
            'platform' => $platform,
            'memory' => $this->memory($macos, $skip),
            'acceleration' => $this->acceleration($macos, $skip),
            'ollama' => $this->ollama(),
            'disk' => $this->disk($destination, $macos, $skip),
        ];
    }

    /**
     * The memory facts and the installed and loaded Ollama models, measured the way
     * facts() measures them. A model-load check reads these without waiting on
     * system_profiler, the ollama binary, or df.
     *
     * @return array{memory: array<string, array<string, mixed>>, ollama: array<string, array<string, mixed>>}
     */
    public function memoryFacts(): array
    {
        $this->runs = [];
        [$macos, $skip] = $this->scope($this->os());
        [, $base, $loopback] = $this->endpoint();

        return [
            'memory' => $this->memory($macos, $skip),
            'ollama' => $this->modelLists($base, $loopback),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $platform
     * @return array{0: bool, 1: string} Whether to run macOS probes, and the reason recorded when Molly does not.
     */
    private function scope(array $platform): array
    {
        $macos = $platform['macos']['status'] === 'measured' && $platform['macos']['value'] === true;
        $skip = $platform['macos']['status'] === 'measured'
            ? 'Molly measures this fact on macOS only; uname -s reported '.$platform['os']['value'].'.'
            : 'The operating system is unknown, so Molly did not run macOS probes.';

        return [$macos, $skip];
    }

    /** @return array{os: array<string, mixed>, macos: array<string, mixed>} */
    private function os(): array
    {
        $os = $this->run('uname -s');

        return [
            'os' => $os['ok'] ? $this->measured(trim($os['output']), 'uname -s', $os['output']) : $this->unknown($os['reason'], 'uname -s'),
            'macos' => $os['ok']
                ? $this->measured(trim($os['output']) === 'Darwin', 'uname -s', $os['output'])
                : $this->unknown('uname -s failed, so the operating system is unknown.', 'uname -s'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function platform(): array
    {
        $facts = $this->os();
        $facts['architecture'] = $this->text('uname -m');

        $macos = $facts['macos']['status'] === 'measured' && $facts['macos']['value'] === true;
        $skip = $facts['macos']['status'] === 'measured'
            ? 'Molly measures this fact on macOS only.'
            : 'The operating system is unknown, so Molly did not run macOS probes.';

        $facts['arm64_capable'] = $macos ? $this->flagFact('sysctl -n hw.optional.arm64') : $this->unknown($skip, 'sysctl -n hw.optional.arm64');
        $facts['translated'] = $macos ? $this->flagFact('sysctl -n sysctl.proc_translated') : $this->unknown($skip, 'sysctl -n sysctl.proc_translated');
        $facts['model_identifier'] = $macos ? $this->text('sysctl -n hw.model') : $this->unknown($skip, 'sysctl -n hw.model');
        $facts['cpu_brand'] = $macos ? $this->text('sysctl -n machdep.cpu.brand_string') : $this->unknown($skip, 'sysctl -n machdep.cpu.brand_string');

        $versions = $macos ? $this->run('sw_vers') : ['ok' => false, 'output' => '', 'reason' => $skip];
        $parsed = $versions['ok'] ? ProbeOutput::swVers($versions['output']) : [];
        foreach (['os_name' => 'ProductName', 'os_version' => 'ProductVersion', 'os_build' => 'BuildVersion'] as $name => $field) {
            $facts[$name] = isset($parsed[$field])
                ? $this->measured($parsed[$field], 'sw_vers', $versions['output'])
                : $this->unknown($versions['ok'] ? "sw_vers did not report {$field}." : $versions['reason'], 'sw_vers');
        }

        return $facts;
    }

    /** @return array<string, array<string, mixed>> */
    private function memory(bool $macos, string $skip): array
    {
        if (! $macos) {
            return array_map(fn (string $source): array => $this->unknown($skip, $source), [
                'total_bytes' => 'sysctl -n hw.memsize',
                'page_size_bytes' => 'sysctl -n hw.pagesize',
                'vm_pages' => 'vm_stat',
                'available_bytes' => 'vm_stat, sysctl -n hw.pagesize',
                'purgeable_bytes' => 'vm_stat, sysctl -n hw.pagesize',
                'compressed_bytes' => 'vm_stat, sysctl -n hw.pagesize',
                'free_percentage' => 'sysctl -n kern.memorystatus_level',
                'pressure_level' => 'sysctl -n kern.memorystatus_vm_pressure_level',
            ]);
        }

        $facts = [
            'total_bytes' => $this->integerFact('sysctl -n hw.memsize'),
            'page_size_bytes' => $this->integerFact('sysctl -n hw.pagesize'),
        ];

        $vm = $this->run('vm_stat');
        $pages = $vm['ok'] ? ProbeOutput::vmStat($vm['output']) : [];
        $facts['vm_pages'] = $pages !== []
            ? $this->measured($pages, 'vm_stat', $vm['output'])
            : $this->unknown($vm['ok'] ? 'vm_stat did not report page counts.' : $vm['reason'], 'vm_stat');

        $pageSize = $facts['page_size_bytes']['status'] === 'measured' ? $facts['page_size_bytes']['value'] : null;
        $facts['available_bytes'] = $this->pageProduct($pages, $pageSize, ['free', 'inactive', 'speculative'], '(free + inactive + speculative) pages × hw.pagesize');
        $facts['purgeable_bytes'] = $this->pageProduct($pages, $pageSize, ['purgeable'], 'purgeable pages × hw.pagesize');
        $facts['compressed_bytes'] = $this->pageProduct($pages, $pageSize, ['compressor_occupied'], 'pages occupied by compressor × hw.pagesize');

        $facts['free_percentage'] = $this->integerFact('sysctl -n kern.memorystatus_level');

        $pressure = $this->run('sysctl -n kern.memorystatus_vm_pressure_level');
        $level = $pressure['ok'] ? ProbeOutput::integer($pressure['output']) : null;
        $name = $level === null ? null : ProbeOutput::pressureLevel($level);
        $facts['pressure_level'] = $name !== null
            ? $this->measured($name, 'sysctl -n kern.memorystatus_vm_pressure_level', $pressure['output'])
            : $this->unknown($pressure['ok'] ? 'The kernel reported an unrecognized pressure level.' : $pressure['reason'], 'sysctl -n kern.memorystatus_vm_pressure_level');

        return $facts;
    }

    /**
     * @param  array<string, int>  $pages
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pageProduct(array $pages, ?int $pageSize, array $keys, string $formula): array
    {
        $source = ['vm_stat', 'sysctl -n hw.pagesize'];
        $missing = array_values(array_diff($keys, array_keys($pages)));

        if ($pageSize === null || $missing !== []) {
            return $this->unknown($pageSize === null ? 'hw.pagesize is unknown.' : 'vm_stat did not report '.implode(', ', $missing).' pages.', $source);
        }

        $raw = array_intersect_key($pages, array_flip($keys)) + ['page_size_bytes' => $pageSize];

        return $this->measured(array_sum(array_intersect_key($pages, array_flip($keys))) * $pageSize, $source, $raw) + ['formula' => $formula];
    }

    /** @return array<string, array<string, mixed>> */
    private function acceleration(bool $macos, string $skip): array
    {
        $source = 'system_profiler SPDisplaysDataType -json';
        $names = ['gpu_model', 'gpu_vendor', 'gpu_cores', 'metal_support', 'metal_available'];

        if (! $macos) {
            return array_fill_keys($names, $this->unknown($skip, $source));
        }

        $run = $this->run($source, 60);
        $gpu = $run['ok'] ? ProbeOutput::displays($run['output']) : null;
        if ($gpu === null) {
            return array_fill_keys($names, $this->unknown($run['ok'] ? 'system_profiler did not report a GPU.' : $run['reason'], $source));
        }

        $field = fn (string $key, string $label, callable $value): array => isset($gpu[$key]) && $value($gpu[$key]) !== null
            ? $this->measured($value($gpu[$key]), $source, $gpu[$key])
            : $this->unknown("system_profiler did not report {$label}.", $source);

        $metal = $gpu['spdisplays_mtlgpufamilysupport'] ?? $gpu['spdisplays_metalfamilysupport'] ?? null;

        return [
            'gpu_model' => $field('sppci_model', 'a GPU model', fn ($v): ?string => is_string($v) ? $v : null),
            'gpu_vendor' => $field('spdisplays_vendor', 'a GPU vendor', fn ($v): ?string => is_string($v) ? preg_replace('/^sppci_vendor_/', '', $v) : null),
            'gpu_cores' => $field('sppci_cores', 'a GPU core count', fn ($v): ?int => is_numeric($v) ? (int) $v : null),
            'metal_support' => is_string($metal)
                ? $this->measured(preg_replace('/^spdisplays_/', '', $metal), $source, $metal)
                : $this->unknown('system_profiler did not report a Metal support family.', $source),
            'metal_available' => is_string($metal)
                ? $this->measured(str_starts_with(preg_replace('/^spdisplays_/', '', $metal) ?? '', 'metal'), $source, $metal)
                : $this->unknown('system_profiler did not report a Metal support family.', $source),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function ollama(): array
    {
        $facts = [];
        $binary = $this->run('command -v ollama');
        $path = $binary['ok'] ? trim($binary['output']) : '';
        $facts['binary_path'] = $path !== ''
            ? $this->measured($path, 'command -v ollama', $binary['output'])
            : $this->unknown($binary['ok'] ? 'ollama is not on PATH.' : 'ollama is not on PATH ('.$binary['reason'].')', 'command -v ollama');

        $versionCommand = ($path !== '' ? escapeshellarg($path) : 'ollama').' --version';
        $version = $path !== '' ? $this->run($versionCommand) : ['ok' => false, 'output' => '', 'reason' => 'The ollama binary was not found.'];
        $cli = $version['ok'] ? ProbeOutput::ollamaVersion($version['output']) : null;
        $facts['cli_version'] = $cli !== null
            ? $this->measured($cli, $versionCommand, $version['output'])
            : $this->unknown($version['ok'] ? 'ollama --version did not print a version.' : $version['reason'], $versionCommand);

        [$url, $base, $loopback] = $this->endpoint();
        $facts['api_url'] = $loopback
            ? $this->measured($base, 'config ai.providers.ollama.url', $url)
            : $this->unknown('Molly only calls a loopback Ollama API over http; the configured URL is not loopback.', 'config ai.providers.ollama.url');

        $version = $this->api($base, '/api/version', $loopback);
        $facts['api_version'] = is_string($version['body']['version'] ?? null)
            ? $this->measured($version['body']['version'], "GET {$base}/api/version", $version['raw'])
            : $this->unknown($version['reason'] ?? 'The API response did not include a version.', "GET {$base}/api/version");

        return $facts + $this->modelLists($base, $loopback);
    }

    /** @return array{0: mixed, 1: string, 2: bool} The configured URL, the base URL Molly calls, and whether that is loopback HTTP. */
    private function endpoint(): array
    {
        $url = config('ai.providers.ollama.url');
        $base = is_string($url) && $url !== '' ? rtrim($url, '/') : 'http://localhost:11434';
        $host = parse_url($base, PHP_URL_HOST);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) && parse_url($base, PHP_URL_SCHEME) === 'http';

        return [$url, $base, $loopback];
    }

    /** @return array{installed_models: array<string, mixed>, loaded_models: array<string, mixed>} */
    private function modelLists(string $base, bool $loopback): array
    {
        return [
            'installed_models' => $this->models($base, '/api/tags', $loopback, fn (array $model): array => [
                'name' => $model['name'] ?? null,
                'digest' => $model['digest'] ?? null,
                'size_bytes' => $model['size'] ?? null,
            ]),
            'loaded_models' => $this->models($base, '/api/ps', $loopback, fn (array $model): array => [
                'name' => $model['name'] ?? null,
                'digest' => $model['digest'] ?? null,
                'size_bytes' => $model['size'] ?? null,
                'size_vram_bytes' => $model['size_vram'] ?? null,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function models(string $base, string $path, bool $loopback, callable $shape): array
    {
        $response = $this->api($base, $path, $loopback);
        $source = "GET {$base}{$path}";
        if (! is_array($response['body']['models'] ?? null)) {
            return $this->unknown($response['reason'] ?? 'The API response did not include a models list.', $source);
        }

        $models = array_values(array_map($shape, array_filter($response['body']['models'], 'is_array')));
        usort($models, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $this->measured($models, $source, $response['raw']);
    }

    /** @return array{body: array<string, mixed>|null, raw: string, reason: string|null} */
    private function api(string $base, string $path, bool $loopback): array
    {
        if (! $loopback) {
            return ['body' => null, 'raw' => '', 'reason' => 'Molly only calls a loopback Ollama API.'];
        }

        try {
            $response = Http::timeout(3)->connectTimeout(2)->acceptJson()->get($base.$path);
        } catch (Throwable $e) {
            return ['body' => null, 'raw' => '', 'reason' => 'The Ollama API did not answer: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['body' => null, 'raw' => $response->body(), 'reason' => "The Ollama API returned HTTP {$response->status()}."];
        }

        $body = $response->json();

        return ['body' => is_array($body) ? $body : null, 'raw' => $response->body(), 'reason' => is_array($body) ? null : 'The Ollama API returned invalid JSON.'];
    }

    /** @return array<string, array<string, mixed>> */
    private function disk(?string $destination, bool $macos, string $skip): array
    {
        [$path, $origin] = $this->destination($destination);
        $facts = ['destination' => $this->measured($path, $origin, $destination ?? $path)];

        $existing = $path;
        while (! is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        $facts['existing_path'] = $this->measured($existing, 'PHP is_dir walk from destination', $existing);
        $facts['destination_exists'] = $this->measured($existing === $path, 'PHP is_dir', is_dir($path));

        $volume = preg_match('#^/Volumes/[^/]+#', $path, $match) === 1 ? $match[0] : null;
        $missing = $volume !== null && ! is_dir($volume);
        $facts['volume_missing'] = $this->measured($missing, 'PHP is_dir on the /Volumes mount for the destination', $volume);

        $df = 'df -kP '.escapeshellarg($existing);
        $run = $this->run($df);
        $row = $run['ok'] ? ProbeOutput::dfPortable($run['output']) : null;
        $dfUnknown = $missing
            ? "The destination volume {$volume} is not mounted; the nearest existing directory is on another volume."
            : ($run['ok'] ? 'df did not report a filesystem row.' : $run['reason']);
        $measure = fn (callable $value): array => $row !== null && ! $missing
            ? $this->measured($value($row), $df, $run['output'])
            : $this->unknown($dfUnknown, $df);

        $facts['mount_point'] = $measure(fn (array $r): string => $r['mount_point']);
        $facts['device'] = $measure(fn (array $r): string => $r['device']);
        $facts['free_bytes'] = $measure(fn (array $r): int => $r['available_kib'] * 1024);
        $facts['total_bytes'] = $measure(fn (array $r): int => $r['total_kib'] * 1024);

        $writable = is_writable($existing);
        $facts['writable'] = $missing
            ? $this->unknown("The destination volume {$volume} is not mounted.", 'PHP is_writable')
            : $this->measured($writable, 'PHP is_writable('.$existing.')', $writable);

        $diskutil = $row !== null && ! $missing ? 'diskutil info -plist '.escapeshellarg($row['mount_point']) : 'diskutil info -plist';
        $info = null;
        $reason = $missing || $row === null ? $dfUnknown : $skip;
        if ($macos && $row !== null && ! $missing) {
            $result = $this->run($diskutil);
            $info = $result['ok'] ? ProbeOutput::plist($result['output']) : null;
            $reason = $result['ok'] ? 'diskutil did not return a property list.' : $result['reason'];
        }

        $plistFact = fn (callable $value, array $keys): array => $info !== null && array_intersect_key($info, array_flip($keys)) !== []
            ? $this->measured($value($info), $diskutil, array_intersect_key($info, array_flip($keys)))
            : $this->unknown($info !== null ? 'diskutil did not report '.implode(' or ', $keys).'.' : $reason, $diskutil);

        $facts['filesystem'] = $plistFact(fn (array $i): mixed => $i['FilesystemType'] ?? $i['FilesystemName'], ['FilesystemType', 'FilesystemName']);
        $facts['volume_name'] = $plistFact(fn (array $i): mixed => $i['VolumeName'], ['VolumeName']);
        $facts['external'] = $plistFact(fn (array $i): bool => ! $i['Internal'], ['Internal']);
        $facts['removable'] = $plistFact(
            fn (array $i): bool => (bool) (($i['RemovableMediaOrExternalDevice'] ?? false) || ($i['Removable'] ?? false) || ($i['Ejectable'] ?? false)),
            ['RemovableMediaOrExternalDevice', 'Removable', 'Ejectable'],
        );

        return $facts;
    }

    /** @return array{0: string, 1: string} */
    private function destination(?string $destination): array
    {
        $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
        $env = getenv('OLLAMA_MODELS');

        [$path, $origin] = match (true) {
            $destination !== null && $destination !== '' => [$destination, '--destination option'],
            is_string($env) && $env !== '' => [$env, 'OLLAMA_MODELS environment variable'],
            default => [$home.'/.ollama/models', 'default ~/.ollama/models'],
        };

        if ($path === '~' || str_starts_with($path, '~/')) {
            $path = $home.substr($path, 1);
        }
        if (! str_starts_with($path, '/')) {
            $path = getcwd().'/'.$path;
        }

        return [rtrim($path, '/') ?: '/', $origin];
    }

    /** @return array<string, mixed> */
    private function text(string $command): array
    {
        $run = $this->run($command);
        $value = trim($run['output']);

        return $run['ok'] && $value !== ''
            ? $this->measured($value, $command, $run['output'])
            : $this->unknown($run['ok'] ? 'The command printed nothing.' : $run['reason'], $command);
    }

    /** @return array<string, mixed> */
    private function integerFact(string $command): array
    {
        $run = $this->run($command);
        $value = $run['ok'] ? ProbeOutput::integer($run['output']) : null;

        return $value !== null
            ? $this->measured($value, $command, $run['output'])
            : $this->unknown($run['ok'] ? 'The command did not print an integer.' : $run['reason'], $command);
    }

    /** @return array<string, mixed> */
    private function flagFact(string $command): array
    {
        $run = $this->run($command);
        $value = $run['ok'] ? ProbeOutput::flag($run['output']) : null;

        return $value !== null
            ? $this->measured($value, $command, $run['output'])
            : $this->unknown($run['ok'] ? 'The command did not print 0 or 1.' : $run['reason'], $command);
    }

    /** @return array{ok: bool, output: string, reason: string} */
    private function run(string $command, int $timeout = 15): array
    {
        if (isset($this->runs[$command])) {
            return $this->runs[$command];
        }

        try {
            $result = Process::timeout($timeout)->run($command);
            $error = trim($result->errorOutput()) ?: trim($result->output());
            $run = $result->successful()
                ? ['ok' => true, 'output' => $result->output(), 'reason' => '']
                : ['ok' => false, 'output' => $result->output(), 'reason' => "`{$command}` exited with {$result->exitCode()}".($error !== '' ? ': '.strtok($error, "\n") : '.')];
        } catch (Throwable $e) {
            $run = ['ok' => false, 'output' => '', 'reason' => "`{$command}` could not run: {$e->getMessage()}"];
        }

        return $this->runs[$command] = $run;
    }

    /**
     * @param  string|list<string>  $source
     * @return array<string, mixed>
     */
    private function measured(mixed $value, string|array $source, mixed $raw): array
    {
        return ['status' => 'measured', 'value' => $value, 'source' => $source, 'raw' => is_string($raw) ? rtrim($raw, "\n") : $raw];
    }

    /**
     * @param  string|list<string>  $source
     * @return array<string, mixed>
     */
    private function unknown(string $reason, string|array $source): array
    {
        return ['status' => 'unknown', 'reason' => $reason, 'source' => $source];
    }
}

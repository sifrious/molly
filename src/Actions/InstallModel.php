<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Hardware\HardwareProbe;
use Sifrious\Molly\ModelFit\InstallationHeadroom;
use Sifrious\Molly\ModelFit\ModelRecords;
use Throwable;

/**
 * Install one approved Ollama model through the local Ollama API, then prove it ready.
 *
 * plan() measures the Mac, decides the fit, and says what an install would download and
 * where, without changing anything. install() takes the size the person authorized,
 * measures and decides again immediately before it starts, holds the install lock, asks
 * Ollama to pull the approved artifact (and to derive it from its pinned base when the
 * catalogue says so), verifies the runtime version and the manifest digest, and runs the
 * readiness check. Every outcome is a status with a code. Molly never downloads for a
 * model that does not fit, has unknown compatibility, or is not approved; never replaces
 * or deletes a model it did not install; and never switches Molly to another model or
 * provider.
 */
class InstallModel
{
    public function __construct(
        private InspectHardware $inspect,
        private DecideModelFit $decide,
        private HardwareProbe $probe,
        private ModelRecords $records,
        private CheckModelReadiness $readiness,
    ) {}

    /**
     * What installing this model would do on this Mac now. Changes nothing.
     *
     * @return array<string, mixed>
     */
    public function plan(?string $model = null, ?string $destination = null): array
    {
        $snapshot = $this->inspect->handle($destination);
        $decision = $this->decide->handle($snapshot);
        $name = $model ?? $decision['model_selection']['model'] ?? $this->blockedOnlyByDestination($decision);
        $plan = ['status' => 'ready', 'code' => null, 'message' => null, 'model' => $name, 'snapshot_sha256' => $snapshot['snapshot_sha256'], 'decision' => $decision];

        if (in_array('catalogue_unusable', $decision['flags'], true)) {
            return $this->refuse($plan, strtoupper($decision['reasons'][0] ?? 'catalogue_unusable'), $decision['message']);
        }
        if ($name === null) {
            return match ($decision['status']) {
                'unknown' => $this->refuse($plan, 'MODEL_FIT_UNKNOWN', $decision['message']),
                'unsupported' => $this->refuse($plan, 'PLATFORM_UNSUPPORTED', $decision['message']),
                default => $this->refuse($plan, 'NO_FIT', DecideModelFit::NO_FIT_MESSAGE.' Reasons: '.implode(', ', $decision['reasons']).'. Molly downloaded nothing.'),
            };
        }

        $candidate = collect($decision['candidates'])->firstWhere('model', $name);
        if ($candidate === null) {
            $admission = collect($decision['installed_models'])->firstWhere('name', $name)['reasons'] ?? $this->decide->catalogue()->decisionFor($name)->rejectionReasons;
            $approved = implode(', ', array_column($decision['candidates'], 'model'));

            return $this->refuse($plan, 'MODEL_NOT_APPROVED', $name.' is not an approved model ('.implode(', ', $admission).'). Molly installs only '.$approved.'. Molly downloaded nothing and changed no model.');
        }

        $entry = (array) $this->decide->catalogue()->approvedEntry($name);
        $facts = $snapshot['facts']['disk'];
        $plan += [
            'digest' => 'sha256:'.$entry['artifact']['digest'],
            'runtime' => ['name' => $decision['runtime_selection']['runtime'], 'version' => $decision['runtime_selection']['version']],
            'install' => $entry['artifact']['install'],
            'candidate' => $candidate,
            'download' => [
                'bytes' => $candidate['download_bytes'],
                'model' => $entry['artifact']['install']['method'] === 'derive' ? $entry['artifact']['install']['from']['name'] : $name,
                'destination' => $facts['destination']['value'] ?? null,
                'destination_exists' => $facts['destination_exists']['value'] ?? null,
                'volume_name' => $facts['volume_name']['value'] ?? null,
                'mount_point' => $facts['mount_point']['value'] ?? null,
                'free_bytes' => $facts['free_bytes']['value'] ?? null,
                'total_bytes' => $facts['total_bytes']['value'] ?? null,
                'partial_download_present' => $this->partialPresent($facts['destination']['value'] ?? null, $entry['artifact']['weights']['digest']),
            ],
        ];

        $blocker = $candidate['install_blockers'][0] ?? null;

        return $blocker === null ? $plan : $this->refuse($plan, ...$this->refusal($blocker, $candidate, $plan));
    }

    /**
     * Install the model after the person authorized a download of up to $authorizedBytes.
     *
     * @param  (Closure(int, int): void)|null  $progress  called with completed and total bytes while Ollama downloads
     * @return array<string, mixed>
     */
    public function install(string $model, ?string $destination = null, int $authorizedBytes = 0, ?Closure $progress = null): array
    {
        LocalOllama::validate($model);
        $events = [];
        $log = function (string $step, string $detail) use (&$events): void {
            $events[] = ['at' => now()->utc()->toIso8601ZuluString(), 'step' => $step, 'detail' => $detail];
        };

        $plan = $this->plan($model, $destination);
        $recheck = ['at' => now()->utc()->toIso8601ZuluString(), 'snapshot_sha256' => $plan['snapshot_sha256'], 'decision_sha256' => $plan['decision']['decision_sha256'], 'status' => $plan['status']];
        $log('recheck', 'Molly measured memory, disk, and the runtime again immediately before installing: snapshot '.$plan['snapshot_sha256'].', decision '.$plan['decision']['status'].', plan '.($plan['code'] ?? 'ready').'.');
        $base = ['model' => $model, 'recheck' => $recheck, 'plan' => array_diff_key($plan, ['decision' => true, 'candidate' => true]), 'decision' => $plan['decision']];
        if ($plan['status'] !== 'ready') {
            return $this->result('refused', $plan['code'], $plan['message'], $base, $events);
        }
        if ($plan['download']['bytes'] > $authorizedBytes) {
            return $this->result('refused', 'DOWNLOAD_AUTHORIZATION_REQUIRED', 'Installing '.$model.' downloads '.InstallationHeadroom::gigabytes($plan['download']['bytes']).', and '.InstallationHeadroom::gigabytes($authorizedBytes).' was authorized. Molly downloaded nothing. Authorize the download shown and run the command again.', $base, $events);
        }

        $lock = $this->records->lock();
        if ($lock === null) {
            return $this->result('refused', 'INSTALL_IN_PROGRESS', 'Another molly:install-model is running on this Mac. Molly downloaded nothing. Wait for it to finish, then run this command again.', $base, $events);
        }

        try {
            return $this->locked($model, $plan, $base, $events, $log, $progress);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $base
     * @param  list<array<string, string>>  $events
     * @param  Closure(string, string): void  $log
     * @return array<string, mixed>
     */
    private function locked(string $model, array $plan, array $base, array &$events, Closure $log, ?Closure $progress): array
    {
        $previous = $this->records->journal($model);
        $base['resumed'] = in_array($previous['state'] ?? null, ['downloading', 'interrupted'], true);
        if ($base['resumed']) {
            $log('resume', 'A previous install of '.$model.' stopped in state '.$previous['state'].' after '.InstallationHeadroom::gigabytes((int) ($previous['completed_bytes'] ?? 0)).'. Ollama continues from the part it kept.');
        }
        $journal = fn (string $state, array $extra) => $this->records->recordJournal($model, [
            'state' => $state,
            'at' => now()->utc()->toIso8601ZuluString(),
            'pid' => getmypid(),
            'snapshot_sha256' => $plan['snapshot_sha256'],
            'digest' => $plan['digest'],
            'download_bytes' => $plan['download']['bytes'],
        ] + $extra);

        $completed = 0;
        if ($plan['download']['bytes'] > 0) {
            $journal('downloading', ['completed_bytes' => (int) ($previous['completed_bytes'] ?? 0)]);
            $log('download', 'Ollama is downloading '.$plan['download']['model'].' ('.InstallationHeadroom::gigabytes($plan['download']['bytes']).') to '.($plan['download']['destination'] ?? 'its models directory').'.');
            $failure = $this->pull($plan['download']['model'], $plan['download']['bytes'], $progress, $completed);
            if ($failure !== null) {
                [$code, $message] = $this->destinationGone($plan) ?? $failure;
                $journal($code === 'DOWNLOAD_INTERRUPTED' ? 'interrupted' : 'failed', ['completed_bytes' => $completed, 'code' => $code]);
                $log('download', $code.': '.$message);

                return $this->result('failed', $code, $message, $base + ['completed_bytes' => $completed], $events);
            }
        }

        $verification = [];
        $failure = $this->verify($model, $plan, $verification, $log);
        $base['verification'] = $verification;
        if ($failure !== null) {
            $journal('failed', ['completed_bytes' => $completed, 'code' => $failure[0]]);

            return $this->result('failed', $failure[0], $failure[1], $base, $events);
        }
        $journal('installed', ['completed_bytes' => $completed]);

        $record = $this->readiness->handle($model, $plan['digest'], $plan['runtime']['version'], (string) $plan['decision']['inputs']['catalogue_sha256']);
        $base['readiness'] = $record;
        $journal($record['passed'] ? 'ready' : 'not_ready', ['completed_bytes' => $completed]);
        $log('readiness', $record['passed'] ? 'The bounded inference and the Molly task both passed.' : 'The readiness check failed.');
        if (! $record['passed']) {
            $failed = $record['checks']['bounded_inference']['passed'] ? $record['checks']['molly_task'] : $record['checks']['bounded_inference'];

            return $this->result('installed_not_ready', 'READINESS_FAILED', $model.' is installed with the approved digest, but the readiness check failed: '.$failed['error'].' Molly does not call it ready. Run this command again to repeat the check.', $base, $events);
        }

        return $this->result('ready', null, $model.' is installed with the approved digest on Ollama '.$plan['runtime']['version'].' and passed the readiness check.', $base, $events)
            + ['next' => ['php artisan molly:setup --agent=ollama --model='.$model, 'php artisan config:clear', 'php artisan molly:doctor']];
    }

    /**
     * Check what Ollama reports after the download: the pinned runtime version from the
     * CLI and the API, the pinned base digest when the model is derived (deriving it when
     * needed), and the approved manifest digest.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $verification  filled with what Molly compared
     * @param  Closure(string, string): void  $log
     * @return array{0: string, 1: string}|null
     */
    private function verify(string $model, array $plan, array &$verification, Closure $log): ?array
    {
        $facts = $this->probe->ollamaFacts();
        $expected = $plan['runtime']['version'];
        $versions = ['cli_version' => $facts['cli_version']['value'] ?? null, 'api_version' => $facts['api_version']['value'] ?? null];
        $verification['runtime'] = $versions + ['expected' => $expected];
        if ($versions['cli_version'] !== $expected || $versions['api_version'] !== $expected) {
            return ['RUNTIME_VERSION_MISMATCH', 'After the download, Ollama reports CLI '.($versions['cli_version'] ?? 'unknown').' and API '.($versions['api_version'] ?? 'unknown').', and the catalogue pins '.$expected.'. Molly did not mark '.$model.' ready. Install Ollama '.$expected.' and run this command again.'];
        }

        $install = $plan['install'];
        if ($install['method'] === 'derive' && $this->installedDigest($facts, $model) !== $plan['digest']) {
            $from = $install['from'];
            $baseDigest = $this->installedDigest($facts, $from['name']);
            $verification['base'] = ['model' => $from['name'], 'expected' => 'sha256:'.$from['digest'], 'installed' => $baseDigest];
            if ($baseDigest !== 'sha256:'.$from['digest']) {
                return $this->digestMismatch($from['name'], $from['digest'], $baseDigest);
            }
            if ($this->installedDigest($facts, $model) === null) {
                $log('derive', 'Ollama is creating '.$model.' from '.$from['name'].' with '.json_encode($install['parameters']).'.');
                $failure = $this->create($model, $from['name'], $install['parameters']);
                if ($failure !== null) {
                    return $failure;
                }
                $facts = $this->probe->ollamaFacts();
            }
        }

        $digest = $this->installedDigest($facts, $model);
        $verification['digest'] = ['expected' => $plan['digest'], 'installed' => $digest];
        if ($digest !== $plan['digest']) {
            return $this->digestMismatch($model, substr($plan['digest'], 7), $digest);
        }
        $log('verify', 'Ollama '.$expected.' lists '.$model.' with the approved digest '.$plan['digest'].'.');

        return null;
    }

    /**
     * Ask Ollama to pull a model and follow its progress stream until it reports success.
     *
     * @return array{0: string, 1: string}|null The failure code and message, or null on success.
     */
    private function pull(string $name, int $expected, ?Closure $progress, int &$completed): ?array
    {
        try {
            $response = Http::withOptions(['stream' => true, 'read_timeout' => 300])->connectTimeout(5)->timeout(0)
                ->post($this->baseUrl().'/api/pull', ['model' => $name, 'stream' => true]);
        } catch (ConnectionException $exception) {
            return ['RUNTIME_UNREACHABLE', 'Molly could not reach Ollama at '.$this->baseUrl().' to start the download: '.$exception->getMessage().'. Start Ollama and run this command again.'];
        }
        if (! $response->successful()) {
            return $this->classify((string) ($response->json('error') ?? 'Ollama answered HTTP '.$response->status().'.'), $name, $completed);
        }

        $layers = [];
        $status = null;
        $failure = null;
        $handle = function (string $line) use (&$layers, &$status, &$failure, &$completed, $name, $expected, $progress): void {
            $event = json_decode(trim($line), true);
            if (! is_array($event)) {
                return;
            }
            if (is_string($event['error'] ?? null)) {
                $failure = $this->classify($event['error'], $name, $completed);

                return;
            }
            $status = is_string($event['status'] ?? null) ? $event['status'] : $status;
            if (is_string($event['digest'] ?? null) && is_int($event['completed'] ?? null)) {
                $layers[$event['digest']] = $event['completed'];
                $completed = array_sum($layers);
                if ($progress !== null) {
                    $progress(min($completed, $expected), $expected);
                }
            }
        };

        $buffer = '';
        $body = $response->toPsrResponse()->getBody();
        try {
            while ($failure === null && ! $body->eof()) {
                $buffer .= $body->read(65536);
                while ($failure === null && ($newline = strpos($buffer, "\n")) !== false) {
                    $handle(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);
                }
            }
            if ($failure === null && trim($buffer) !== '') {
                $handle($buffer);
            }
        } catch (Throwable $exception) {
            return ['DOWNLOAD_INTERRUPTED', 'The download of '.$name.' stopped after '.InstallationHeadroom::gigabytes($completed).': '.$exception->getMessage().'. Ollama keeps the part it downloaded; run this command again to resume.'];
        }
        if ($failure !== null) {
            return $failure;
        }

        return $status === 'success' ? null : ['DOWNLOAD_INTERRUPTED', 'The download of '.$name.' stopped after '.InstallationHeadroom::gigabytes($completed).' without Ollama reporting success. Ollama keeps the part it downloaded; run this command again to resume.'];
    }

    /**
     * Ask Ollama to create a model from its pinned base with fixed parameters, as
     * `ollama create` does from a Modelfile. Nothing is downloaded.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{0: string, 1: string}|null
     */
    private function create(string $name, string $from, array $parameters): ?array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(600)->post($this->baseUrl().'/api/create', ['model' => $name, 'from' => $from, 'parameters' => $parameters, 'stream' => false]);
        } catch (ConnectionException $exception) {
            return ['RUNTIME_UNREACHABLE', 'Molly could not reach Ollama at '.$this->baseUrl().' to create '.$name.': '.$exception->getMessage().'.'];
        }

        return $response->successful() && $response->json('status') === 'success'
            ? null
            : ['CREATE_FAILED', 'Ollama did not create '.$name.' from '.$from.': '.$this->firstLine((string) ($response->json('error') ?? 'HTTP '.$response->status())).'. Molly did not mark it ready.'];
    }

    /** @return array{0: string, 1: string} */
    private function classify(string $error, string $name, int $completed): array
    {
        $text = strtolower($error);
        $detail = ' Ollama said: '.$this->firstLine($error);
        $has = fn (array $needles): bool => array_filter($needles, fn (string $needle): bool => str_contains($text, $needle)) !== [];

        return match (true) {
            $has(['digest mismatch', 'sha256 digest', 'corrupt']) => ['DIGEST_MISMATCH', 'Ollama rejected a downloaded part of '.$name.' whose SHA-256 did not match, and kept none of it. Run this command again to download that part again.'.$detail],
            $has(['read-only file system', 'permission denied', 'operation not permitted']) => ['DESTINATION_READ_ONLY', 'Ollama could not write '.$name.' to its models directory, which is read-only for it. Molly installed nothing.'.$detail],
            $has(['no space left']) => ['DISK_FULL', 'The models volume ran out of space after '.InstallationHeadroom::gigabytes($completed).'. Free space, then run this command again; Ollama resumes the part it kept.'.$detail],
            $has(['no such host', 'dial tcp', 'network is unreachable', 'i/o timeout', 'tls handshake', 'connection refused', 'connection reset', 'could not resolve', 'name resolution']) => ['DOWNLOAD_OFFLINE', 'Ollama could not reach its registry to download '.$name.'. Check the network connection, then run this command again.'.$detail],
            default => ['DOWNLOAD_FAILED', 'Ollama did not download '.$name.'.'.$detail],
        };
    }

    /**
     * When the destination disappeared during a failed download, say so instead of
     * repeating Ollama's error.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: string, 1: string}|null
     */
    private function destinationGone(array $plan): ?array
    {
        $destination = $plan['download']['destination'];
        if (! is_string($destination)) {
            return null;
        }
        $volume = preg_match('#^/Volumes/[^/]+#', $destination, $match) === 1 ? $match[0] : null;
        $gone = ($volume !== null && ! is_dir($volume)) || ($plan['download']['destination_exists'] === true && ! is_dir($destination));

        return $gone ? ['DESTINATION_REMOVED', 'The models directory '.$destination.' disappeared during the download'.($volume !== null ? ', because '.$volume.' is no longer mounted' : '').'. Molly installed nothing. Reconnect the volume, then run this command again.'] : null;
    }

    /** @return array{0: string, 1: string} */
    private function digestMismatch(string $name, string $expected, ?string $installed): array
    {
        return ['DIGEST_MISMATCH', 'Ollama lists '.$name.' with digest '.($installed ?? 'none').', not the approved sha256:'.$expected.'. Molly did not mark it ready and will not use it. Molly does not delete models; remove it with ollama rm '.$name.' if you do not want it.'];
    }

    /** @param  array<string, array<string, mixed>>  $facts */
    private function installedDigest(array $facts, string $name): ?string
    {
        foreach ($facts['installed_models']['value'] ?? [] as $model) {
            if (is_array($model) && ($model['name'] ?? null) === $name && is_string($model['digest'] ?? null)) {
                return 'sha256:'.strtolower($model['digest']);
            }
        }

        return null;
    }

    /**
     * When no model fits only because of the destination, name the model that would fit
     * otherwise, so the refusal can say what is wrong with the destination.
     *
     * @param  array<string, mixed>  $decision
     */
    private function blockedOnlyByDestination(array $decision): ?string
    {
        foreach ($decision['candidates'] as $candidate) {
            if ($candidate['reasons'] !== [] && array_diff($candidate['reasons'], ['destination_volume_missing', 'destination_read_only', 'insufficient_disk']) === []) {
                return $candidate['model'];
            }
        }

        return null;
    }

    private function partialPresent(?string $destination, string $weights): bool
    {
        return is_string($destination) && glob($destination.'/blobs/sha256-'.$weights.'-partial*') !== [];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $plan
     * @return array{0: string, 1: string}
     */
    private function refusal(string $blocker, array $candidate, array $plan): array
    {
        $name = $candidate['model'];
        $download = $plan['download'];
        $runtime = $plan['decision']['runtime_selection'];
        $reasons = $candidate['reasons'];

        return match (true) {
            in_array('insufficient_memory', $reasons, true) => ['MODEL_DOES_NOT_FIT', $name.' does not fit this Mac ('.implode(', ', $reasons).'). It needs '.InstallationHeadroom::gigabytes($candidate['required']['minimum_memory_bytes']).' of memory at minimum. Molly downloaded nothing.'],
            in_array('destination_volume_missing', $reasons, true) => ['DESTINATION_VOLUME_MISSING', 'The volume for '.$download['destination'].' is not mounted. Molly downloaded nothing.'],
            in_array('destination_read_only', $reasons, true) => ['DESTINATION_READ_ONLY', 'Molly cannot write to '.$download['destination'].'. Molly downloaded nothing.'],
            in_array('insufficient_disk', $reasons, true) => ['DISK_INSUFFICIENT', $name.' needs '.InstallationHeadroom::gigabytes((int) $candidate['required']['disk_bytes']).' free on '.($download['mount_point'] ?? 'the models volume').' so that 15% of the volume stays free after the download, and '.InstallationHeadroom::gigabytes((int) $download['free_bytes']).' is free. Molly downloaded nothing.'],
            $blocker === 'no_fit' => ['MODEL_DOES_NOT_FIT', $name.' does not fit this Mac ('.implode(', ', $reasons).'). It needs '.InstallationHeadroom::gigabytes($candidate['required']['minimum_memory_bytes']).' of memory at minimum. Molly downloaded nothing.'],
            $blocker === 'unknown' => ['MODEL_FIT_UNKNOWN', 'Molly could not decide whether '.$name.' fits this Mac, because it could not measure: '.implode(', ', $reasons).'. This is not a finding that it does not fit. Molly downloaded nothing.'],
            $blocker === 'unsupported' => ['PLATFORM_UNSUPPORTED', $plan['decision']['message'].' Molly downloaded nothing.'],
            $blocker === 'catalogue_stale' => ['CATALOGUE_STALE', 'Molly\'s approved model catalogue was due for review on '.$plan['decision']['inputs']['catalogue_review_by'].'. Molly downloads nothing from a stale catalogue. Update Molly for a reviewed catalogue.'],
            $blocker === 'runtime_version_mismatch' => ['RUNTIME_VERSION_MISMATCH', 'Ollama reports CLI '.($runtime['installed']['cli_version'] ?? 'unknown').' and API '.($runtime['installed']['api_version'] ?? 'unknown').', and the catalogue pins '.$runtime['version'].'. Molly does not install or change the runtime. Install Ollama '.$runtime['version'].', then run this command again.'],
            str_starts_with($blocker, 'runtime_') => ['RUNTIME_UNVERIFIED', 'Molly could not confirm Ollama '.$runtime['version'].' from both the CLI and the API ('.implode(', ', $runtime['reasons']).'). Start Ollama '.$runtime['version'].', then run this command again. Molly downloaded nothing.'],
            $blocker === 'installed_digest_mismatch' => ['INSTALLED_DIGEST_CONFLICT', $name.' is already installed with a digest other than the approved '.$plan['digest'].'. Molly does not replace or delete it. Remove it with ollama rm '.$name.' if you want the approved artifact.'],
            $blocker === 'base_digest_mismatch' => ['BASE_DIGEST_CONFLICT', $plan['install']['from']['name'].' is installed with a digest other than the pinned sha256:'.$plan['install']['from']['digest'].', so Molly cannot derive '.$name.' from it. Molly does not replace or delete it.'],
            $blocker === 'memory_pressure_critical' => ['MEMORY_PRESSURE_CRITICAL', 'macOS reports critical memory pressure. Molly does not install or check a model now. Close other applications, then run this command again.'],
            default => ['INSTALL_REFUSED', 'Molly will not install '.$name.': '.$blocker.'.'],
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function refuse(array $plan, string $code, string $message): array
    {
        return ['status' => 'refused', 'code' => $code, 'message' => $message] + $plan;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  list<array<string, string>>  $events
     * @return array<string, mixed>
     */
    private function result(string $status, ?string $code, string $message, array $base, array $events): array
    {
        return ['status' => $status, 'code' => $code, 'message' => $message] + $base + ['events' => $events];
    }

    private function baseUrl(): string
    {
        $url = config('ai.providers.ollama.url');
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('LOCAL_PROVIDER_INVALID: Use a local Ollama model and a loopback HTTP URL.');
        }

        return rtrim($url, '/');
    }

    private function firstLine(string $text): string
    {
        return mb_substr(trim((string) strtok(trim($text), "\n")), 0, 300);
    }
}

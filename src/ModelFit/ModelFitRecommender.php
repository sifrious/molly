<?php

namespace Sifrious\Molly\ModelFit;

/**
 * Decides which approved model fits one machine. Every approved catalogue entry is
 * assessed on its own, then the best fit is selected: the largest model that fits with
 * the memory headroom, otherwise the smallest model that meets its minimum. Precedence
 * inside one assessment is fixed: an unsupported platform, then a measured shortfall
 * (no_fit), then an unknown fact (unknown), then the memory tier. Warning or critical
 * memory pressure lowers the tier, except that warning pressure does not lower a model
 * Ollama already holds when that model has its memory and headroom. Unknown never becomes
 * no_fit or unsupported. The recommender reads facts only and has no side effects.
 */
final class ModelFitRecommender
{
    /**
     * @param  array<string, array<string, mixed>>  $readiness  readiness records keyed by model name
     */
    public function recommend(TrustedModelCatalogue $catalogue, MachineFacts $machine, InstallationHeadroom $headroom, array $readiness = []): ModelFitRecommendation
    {
        return $this->select($this->assessAll($catalogue, $machine, $headroom, $readiness), $machine);
    }

    /**
     * @param  array<string, array<string, mixed>>  $readiness
     * @return list<ModelFitRecommendation>
     */
    public function assessAll(TrustedModelCatalogue $catalogue, MachineFacts $machine, InstallationHeadroom $headroom, array $readiness = []): array
    {
        $entries = $catalogue->approvedEntries();
        usort($entries, static fn (array $a, array $b): int => [$a['artifact']['size_bytes'], $a['identity']['model_id']]
            <=> [$b['artifact']['size_bytes'], $b['identity']['model_id']]
        );
        $runtime = $this->runtime($catalogue, $machine);

        $candidates = [];
        foreach ($entries as $entry) {
            $runtimes = $entry['runtime_compatibility'];
            usort($runtimes, static fn (array $a, array $b): int => $a['runtime'] <=> $b['runtime']);

            $fallback = null;
            $chosen = null;
            foreach ($runtimes as $compatibility) {
                $pin = $catalogue->runtimeFor($compatibility) ?? [];
                $assessment = $this->assess($entry, $compatibility, $pin, $machine, $headroom, $runtime, $readiness[$entry['artifact']['name']] ?? null);
                $fallback ??= $assessment;
                if ($assessment->status->fits()) {
                    $chosen = $assessment;
                    break;
                }
            }
            $candidates[] = $chosen ?? $fallback;
        }

        return array_values(array_filter($candidates));
    }

    /**
     * The selected candidate, or a decision without a model that names why none fits.
     * A model a run would refuse to load with the memory available now is never
     * selected. Among the rest, a recommended fit comes before a minimum fit; within a
     * tier, a model Ollama holds that passed Molly's readiness check comes first, then
     * the largest model with headroom or the smallest at the minimum. When every
     * fitting model would be refused, the status is memory_unavailable and names the
     * model that would be selected once that memory is free.
     *
     * @param  list<ModelFitRecommendation>  $candidates
     */
    public function select(array $candidates, MachineFacts $machine): ModelFitRecommendation
    {
        $fits = $this->ranked(array_values(array_filter($candidates, fn (ModelFitRecommendation $candidate): bool => $candidate->status->fits())));
        $runnable = array_values(array_filter($fits, fn (ModelFitRecommendation $candidate): bool => ! in_array('memory_available_insufficient', $candidate->constraints, true)));
        if ($runnable !== []) {
            return $runnable[0];
        }
        if ($fits !== []) {
            $best = $fits[0];

            return new ModelFitRecommendation(
                ModelFitStatus::MemoryUnavailable, $best->modelId, $best->runtime, ['memory_available_insufficient'], $best->constraints, $best->fit,
                $best->measured, $best->required, $best->installed, $best->verified, $best->downloadBytes, $best->loaded,
            );
        }

        $statuses = array_map(fn (ModelFitRecommendation $candidate): ModelFitStatus => $candidate->status, $candidates);
        $status = match (true) {
            $candidates === [] => ModelFitStatus::NoFit,
            array_filter($statuses, fn (ModelFitStatus $status): bool => $status !== ModelFitStatus::Unsupported) === [] => ModelFitStatus::Unsupported,
            in_array(ModelFitStatus::Unknown, $statuses, true) => ModelFitStatus::Unknown,
            default => ModelFitStatus::NoFit,
        };
        $reasons = $candidates === []
            ? ['no_approved_model']
            : array_values(array_unique(array_merge(...array_map(fn (ModelFitRecommendation $candidate): array => $candidate->reasons, $candidates))));

        return new ModelFitRecommendation($status, null, null, $reasons, measured: $this->measured($machine));
    }

    /**
     * @param  list<ModelFitRecommendation>  $fits
     * @return list<ModelFitRecommendation>
     */
    private function ranked(array $fits): array
    {
        usort($fits, static function (ModelFitRecommendation $a, ModelFitRecommendation $b): int {
            $tier = ($a->fit === ModelFitStatus::RecommendedFit ? 0 : 1) <=> ($b->fit === ModelFitStatus::RecommendedFit ? 0 : 1);
            if ($tier !== 0) {
                return $tier;
            }
            // A model Ollama holds and Molly verified runs now without loading anything.
            $ready = ($a->loaded && $a->verified ? 0 : 1) <=> ($b->loaded && $b->verified ? 0 : 1);
            if ($ready !== 0) {
                return $ready;
            }
            $size = $a->required['model_bytes'] <=> $b->required['model_bytes'];

            // With headroom, the larger model is more capable; at the minimum, the smaller one leaves more room.
            return $a->fit === ModelFitStatus::RecommendedFit ? -$size : $size;
        });

        return $fits;
    }

    /**
     * The runtime selection, kept apart from the model: the pinned version and whether
     * the installed runtime reports exactly that version from its CLI and its API.
     *
     * @return array{runtime: string|null, version: string|null, source: string|null, minimum_os_version: string|null, status: string, reasons: list<string>, installed: array{cli_version: string|null, api_version: string|null}}
     */
    public function runtime(TrustedModelCatalogue $catalogue, MachineFacts $machine): array
    {
        $pin = $catalogue->runtimes()[0] ?? null;
        $installed = ['cli_version' => $machine->runtimeCliVersion, 'api_version' => $machine->runtimeApiVersion];
        if ($pin === null) {
            return ['runtime' => null, 'version' => null, 'source' => null, 'minimum_os_version' => null, 'status' => 'unpinned', 'reasons' => ['no_pinned_runtime'], 'installed' => $installed];
        }

        $reasons = [];
        foreach (['cli' => $machine->runtimeCliVersion, 'api' => $machine->runtimeApiVersion] as $source => $version) {
            if ($version === null) {
                $reasons[] = "compatibility_unknown:ollama.{$source}_version";
            } elseif ($version !== $pin['version']) {
                $reasons[] = "runtime_version_mismatch:{$source}";
            }
        }
        $status = match (true) {
            $reasons === [] => 'verified',
            (bool) array_filter($reasons, fn (string $reason): bool => str_starts_with($reason, 'runtime_version_mismatch')) => 'version_mismatch',
            default => 'unverified',
        };

        return [
            'runtime' => $pin['runtime'],
            'version' => $pin['version'],
            'source' => $pin['source'],
            'minimum_os_version' => $pin['minimum_os_version'],
            'status' => $status,
            'reasons' => $reasons,
            'installed' => $installed,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $compatibility
     * @param  array<string, mixed>  $pin
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>|null  $readiness
     */
    private function assess(array $entry, array $compatibility, array $pin, MachineFacts $machine, InstallationHeadroom $headroom, array $runtime, ?array $readiness): ModelFitRecommendation
    {
        $modelId = $entry['identity']['model_id'];
        $runtimeName = $compatibility['runtime'];
        $size = $entry['artifact']['size_bytes'];
        $recommendedTotal = max($entry['hardware']['recommended_memory_bytes'], $headroom->memoryRequired($size));
        $required = [
            'model_bytes' => $size,
            'minimum_memory_bytes' => $entry['hardware']['minimum_memory_bytes'],
            'recommended_memory_bytes' => $recommendedTotal,
            'memory_headroom_bytes' => $headroom->memoryBytes,
            'minimum_os_version' => $pin['minimum_os_version'] ?? null,
            'runtime_version' => $pin['version'] ?? null,
        ];
        [$download, $installedMatch, $installConstraints, $listUnknown] = $this->download($entry, $machine);
        $required['download_bytes'] = $download;
        $measured = $this->measured($machine);
        $result = static fn (ModelFitStatus $status, array $reasons, array $required, int $download = 0): ModelFitRecommendation => new ModelFitRecommendation(
            $status, $modelId, $runtimeName, array_values(array_unique($reasons)), measured: $measured, required: $required, downloadBytes: $download,
        );

        [$platform, $platformReasons] = $this->platform($machine, $compatibility, $pin);
        if ($platform === 'unsupported') {
            return $result(ModelFitStatus::Unsupported, $platformReasons, $required, $download);
        }

        $shortfalls = [];
        $unknowns = $platform === 'unknown' ? $platformReasons : [];

        foreach ($compatibility['requirements'] as $prerequisite) {
            $satisfied = $prerequisite === 'metal' ? $machine->metalAvailable : null;
            if ($satisfied === false) {
                return $result(ModelFitStatus::Unsupported, ["runtime_prerequisite_missing:{$prerequisite}"], $required, $download);
            }
            if ($satisfied === null) {
                $unknowns[] = "runtime_prerequisite_unknown:{$prerequisite}";
            }
        }

        if ($machine->totalMemoryBytes === null) {
            $unknowns[] = 'compatibility_unknown:memory.total_bytes';
        } elseif ($machine->totalMemoryBytes < $entry['hardware']['minimum_memory_bytes']) {
            $shortfalls[] = 'insufficient_memory';
        }

        if ($download > 0) {
            $required['disk_bytes'] = $machine->totalDiskBytes === null ? null : $headroom->diskRequired($download, $machine->totalDiskBytes);
            $disk = match (true) {
                $machine->volumeMissing === true => 'destination_volume_missing',
                $machine->destinationWritable === false => 'destination_read_only',
                $machine->freeDiskBytes === null || $machine->totalDiskBytes === null => 'compatibility_unknown:disk.free_bytes',
                ! $headroom->fitsDisk($download, $machine->freeDiskBytes, $machine->totalDiskBytes) => 'insufficient_disk',
                default => null,
            };
            if ($disk !== null && ($listUnknown || str_starts_with($disk, 'compatibility_unknown'))) {
                // The model may already be installed, or the disk is unknown: not a measured shortfall.
                $unknowns[] = $disk;
            } elseif ($disk !== null) {
                $shortfalls[] = $disk;
            }
        }
        if ($listUnknown) {
            $unknowns[] = 'compatibility_unknown:ollama.installed_models';
        }

        if ($shortfalls !== []) {
            return $result(ModelFitStatus::NoFit, [...$shortfalls, ...$unknowns], $required, $download);
        }
        if ($unknowns !== []) {
            return $result(ModelFitStatus::Unknown, $unknowns, $required, $download);
        }

        $fit = $machine->totalMemoryBytes >= $recommendedTotal ? ModelFitStatus::RecommendedFit : ModelFitStatus::MinimumFit;
        $constraints = $fit === ModelFitStatus::MinimumFit ? ['memory_headroom_below_budget'] : [];
        $downgrade = match ($machine->memoryPressure) {
            'warning' => 'memory_pressure_warning',
            'critical' => 'memory_pressure_critical',
            'normal' => null,
            default => 'memory_pressure_unknown',
        };
        if ($downgrade !== null) {
            $constraints[] = $downgrade;
        }
        if ($downgrade === 'memory_pressure_warning' && $this->holdsItsOwnMemory($entry, $machine, $headroom)) {
            // The pressure may come from this very model: the memory it holds counts as available to it.
            $constraints[] = 'memory_held_by_loaded_model';
        } elseif (in_array($downgrade, ['memory_pressure_warning', 'memory_pressure_critical'], true)) {
            $fit = ModelFitStatus::MinimumFit;
        }
        if ($machine->translated === true) {
            $constraints[] = 'process_translated';
            $fit = ModelFitStatus::MinimumFit;
        }
        $constraints = [...$constraints, ...$installConstraints];
        if ($this->memoryGateRefuses($entry, $machine, $headroom)) {
            $constraints[] = 'memory_available_insufficient';
        }
        $loaded = $machine->loaded($entry['artifact']['name'], $entry['artifact']['digest']) !== null;

        $verified = $installedMatch
            && $runtime['status'] === 'verified'
            && ($readiness['passed'] ?? false) === true
            && ($readiness['digest'] ?? null) === 'sha256:'.$entry['artifact']['digest']
            && ($readiness['runtime']['version'] ?? null) === ($pin['version'] ?? null);

        $status = $verified ? ModelFitStatus::AlreadyInstalled : $fit;
        $reasons = match (true) {
            $verified => ['artifact_already_installed', 'readiness_verified'],
            $fit === ModelFitStatus::RecommendedFit => ['recommended_memory_available'],
            default => ['minimum_requirements_met'],
        };
        if ($installedMatch && ! $verified) {
            $reasons[] = 'artifact_installed_unverified';
        }

        return new ModelFitRecommendation(
            $status, $modelId, $runtimeName, $reasons, array_values(array_unique($constraints)), $fit,
            $measured, $required, $installedMatch, $verified, $download, $loaded,
        );
    }

    /**
     * Whether Ollama already holds this exact model and the model has its size plus the
     * headroom once the memory it holds counts as available to it. Doctor and a run treat a
     * loaded model the same way: using it needs no new memory. When the loaded model or the
     * available memory is unknown, the answer is no, so warning pressure still downgrades.
     *
     * @param  array<string, mixed>  $entry
     */
    private function holdsItsOwnMemory(array $entry, MachineFacts $machine, InstallationHeadroom $headroom): bool
    {
        $loaded = $machine->loaded($entry['artifact']['name'], $entry['artifact']['digest'])['size_bytes'] ?? null;

        return $loaded !== null && $machine->availableMemoryBytes !== null
            && $headroom->fitsMemory($entry['artifact']['size_bytes'], $machine->availableMemoryBytes + $loaded);
    }

    /**
     * Whether a run would refuse to load this model now with MODEL_MEMORY_INSUFFICIENT.
     * This is LocalOllama::memory() on the snapshot's facts: a model Ollama holds needs
     * no new memory; otherwise the installed size plus the headroom must fit in the
     * available memory. When the size, the available memory, or the loaded list is
     * unknown, a run does not refuse, and neither does this.
     *
     * @param  array<string, mixed>  $entry
     */
    private function memoryGateRefuses(array $entry, MachineFacts $machine, InstallationHeadroom $headroom): bool
    {
        $name = $entry['artifact']['name'];
        $holds = $machine->holds($name);
        $installed = $machine->installed($name);
        $size = is_array($installed) ? $installed['size_bytes'] : null;

        return $holds === false && $size !== null && $machine->availableMemoryBytes !== null
            && ! $headroom->fitsMemory($size, $machine->availableMemoryBytes);
    }

    /**
     * What installing this entry would download, whether the approved artifact is already
     * installed, the constraints an existing model with the same name adds, and whether
     * the installed model list is unknown.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: int, 1: bool, 2: list<string>, 3: bool}
     */
    private function download(array $entry, MachineFacts $machine): array
    {
        $artifact = $entry['artifact'];
        $installed = $machine->installed($artifact['name']);
        if ($installed === null) {
            return [$this->fullDownload($artifact), false, [], true];
        }
        if (is_array($installed) && hash_equals($artifact['digest'], $installed['digest'])) {
            return [0, true, [], false];
        }
        $constraints = is_array($installed) ? ['installed_digest_mismatch'] : [];

        if ($artifact['install']['method'] !== 'derive') {
            return [$artifact['size_bytes'], false, $constraints, false];
        }

        $from = $artifact['install']['from'];
        $base = $machine->installed($from['name']);
        if (is_array($base) && hash_equals($from['digest'], $base['digest'])) {
            return [0, false, $constraints, false];
        }
        if (is_array($base)) {
            $constraints[] = 'base_digest_mismatch';
        }

        return [$from['size_bytes'], false, $constraints, false];
    }

    /** @param  array<string, mixed>  $artifact */
    private function fullDownload(array $artifact): int
    {
        return $artifact['install']['method'] === 'derive' ? $artifact['install']['from']['size_bytes'] : $artifact['size_bytes'];
    }

    /**
     * Whether the machine is a platform the runtime supports. Molly reads the arm64
     * capability before uname -m, so a translated process on Apple silicon is not
     * mistaken for an Intel Mac.
     *
     * @param  array<string, mixed>  $compatibility
     * @param  array<string, mixed>  $pin
     * @return array{0: 'supported'|'unsupported'|'unknown', 1: list<string>}
     */
    private function platform(MachineFacts $machine, array $compatibility, array $pin): array
    {
        if ($machine->macos === false) {
            return ['unsupported', ['platform_unsupported:'.strtolower($machine->os ?? 'unknown')]];
        }
        if ($machine->macos === null) {
            return ['unknown', ['compatibility_unknown:platform.macos']];
        }

        $apple = $machine->appleSilicon();
        if ($apple === false) {
            return ['unsupported', ['platform_unsupported:intel']];
        }
        if (! in_array('macos-apple-silicon', $compatibility['platforms'], true)) {
            return ['unsupported', ['platform_unsupported:macos-apple-silicon']];
        }

        $unknowns = [];
        if ($apple === null) {
            $unknowns[] = 'compatibility_unknown:platform.arm64_capable';
        }
        $minimum = $pin['minimum_os_version'] ?? null;
        if ($machine->osVersion === null) {
            $unknowns[] = 'compatibility_unknown:platform.os_version';
        } elseif (is_string($minimum) && version_compare($machine->osVersion, $minimum, '<')) {
            return ['unsupported', ['os_version_unsupported']];
        }

        return $unknowns === [] ? ['supported', []] : ['unknown', $unknowns];
    }

    /** @return array<string, mixed> */
    private function measured(MachineFacts $machine): array
    {
        return [
            'os' => $machine->os,
            'os_version' => $machine->osVersion,
            'architecture' => $machine->architecture,
            'apple_silicon' => $machine->appleSilicon(),
            'translated' => $machine->translated,
            'total_memory_bytes' => $machine->totalMemoryBytes,
            'available_memory_bytes' => $machine->availableMemoryBytes,
            'memory_pressure' => $machine->memoryPressure,
            'metal_available' => $machine->metalAvailable,
            'destination' => $machine->destination,
            'volume_name' => $machine->volumeName,
            'mount_point' => $machine->mountPoint,
            'volume_missing' => $machine->volumeMissing,
            'destination_writable' => $machine->destinationWritable,
            'free_disk_bytes' => $machine->freeDiskBytes,
            'total_disk_bytes' => $machine->totalDiskBytes,
        ];
    }
}

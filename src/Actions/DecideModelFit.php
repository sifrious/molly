<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Hardware\HardwareSnapshot;
use Sifrious\Molly\ModelFit\AdmissionPolicy;
use Sifrious\Molly\ModelFit\InstallationHeadroom;
use Sifrious\Molly\ModelFit\MachineFacts;
use Sifrious\Molly\ModelFit\ModelFitRecommendation;
use Sifrious\Molly\ModelFit\ModelFitRecommender;
use Sifrious\Molly\ModelFit\ModelFitStatus;
use Sifrious\Molly\ModelFit\ModelRecords;
use Sifrious\Molly\ModelFit\TrustedModelCatalogue;

/**
 * Decide which approved local Ollama model fits the machine a hardware snapshot
 * describes, and return a molly.model-fit-decision/1 document. The decision depends
 * only on the snapshot, the bundled catalogue, molly.memory.headroom_gb, the readiness
 * records, and the configured model, and it names each input, so any reader with the
 * same inputs reaches the same decision. Deciding never downloads, loads, or selects
 * a model.
 */
class DecideModelFit
{
    public const SCHEMA = 'molly.model-fit-decision/1';

    public const NO_FIT_MESSAGE = 'No supported local Ollama configuration fits this Mac.';

    public function __construct(
        private ModelFitRecommender $recommender,
        private ModelRecords $records,
        private ?string $cataloguePath = null,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot  a molly.hardware-snapshot/1 document
     * @return array<string, mixed>
     */
    public function handle(array $snapshot): array
    {
        $machine = MachineFacts::fromSnapshot($snapshot);
        $headroom = InstallationHeadroom::fromConfig();
        $inputs = [
            'catalogue_version' => null,
            'catalogue_sha256' => null,
            'catalogue_review_by' => null,
            'policy_version' => AdmissionPolicy::VERSION,
            'measured_on' => substr($machine->measuredAt, 0, 10),
            'memory_headroom_bytes' => $headroom->memoryBytes,
            'minimum_free_disk_fraction' => $headroom->minimumFreeDiskFraction,
            'readiness_sha256' => $this->records->readinessDigest(),
            'configured_model' => is_string(config('molly.model')) && config('molly.model') !== '' ? config('molly.model') : null,
        ];

        try {
            $catalogue = $this->catalogue();
        } catch (RuntimeException $exception) {
            return $this->document($machine, $inputs, [
                'status' => ModelFitStatus::Unknown->value,
                'message' => 'Molly could not use its approved model catalogue, so it cannot decide which model fits this Mac. It downloads nothing. '.$exception->getMessage(),
                'reasons' => [strtolower((string) strtok($exception->getMessage(), ':'))],
                'constraints' => [],
                'runtime_selection' => null,
                'model_selection' => null,
                'install_allowed' => false,
                'install_blockers' => ['catalogue_unusable'],
                'flags' => ['catalogue_unusable'],
                'candidates' => [],
                'installed_models' => [],
                'configured_model' => null,
                'measured' => [],
            ]);
        }

        $inputs['catalogue_version'] = $catalogue->catalogueVersion;
        $inputs['catalogue_sha256'] = $catalogue->catalogueDigest;
        $inputs['catalogue_review_by'] = $catalogue->reviewBy;

        $candidates = $this->recommender->assessAll($catalogue, $machine, $headroom, $this->records->readiness());
        $selected = $this->recommender->select($candidates, $machine);
        $runtime = $this->recommender->runtime($catalogue, $machine);
        $stale = $catalogue->staleOn($inputs['measured_on']);
        $installed = $this->installedModels($catalogue, $machine);
        $configured = $this->configuredModel($catalogue, $machine, $inputs['configured_model']);

        $rows = array_map(fn (ModelFitRecommendation $candidate): array => array_diff_key($candidate->toArray(), ['measured' => true])
            + ['install_blockers' => $this->blockers($candidate->status, $candidate->constraints, $runtime['status'], $stale)], $candidates);
        $blockers = $this->blockers($selected->status, $selected->constraints, $runtime['status'], $stale);

        $flags = [];
        if ($stale) {
            $flags[] = 'catalogue_stale';
        }
        if ($runtime['status'] !== 'verified') {
            $flags[] = 'runtime_'.$runtime['status'];
        }
        foreach ($installed as $model) {
            if ($model['admission'] === 'digest_mismatch') {
                $flags[] = 'installed_digest_mismatch:'.$model['name'];
            }
        }
        if ($configured !== null && $configured['admission'] !== 'approved') {
            $flags[] = 'configured_model_not_approved:'.$configured['name'];
        }
        // Ollama may unload a model it holds to load another one, depending on OLLAMA_MAX_LOADED_MODELS.
        if ($selected->status->fits() && ! $selected->loaded) {
            foreach (array_unique(array_column($machine->loadedModels ?? [], 'name')) as $held) {
                $flags[] = 'loaded_model_may_unload:'.$held;
            }
        }

        return $this->document($machine, $inputs, [
            'status' => $selected->status->value,
            'message' => $this->message($selected, $catalogue, $headroom),
            'reasons' => $selected->reasons,
            'constraints' => $selected->constraints,
            'runtime_selection' => $runtime,
            'model_selection' => $selected->modelId === null || ! $selected->status->fits() ? null : $this->modelSelection($catalogue, $selected),
            'install_allowed' => $blockers === [],
            'install_blockers' => $blockers,
            'flags' => $flags,
            'candidates' => $rows,
            'installed_models' => $installed,
            'configured_model' => $configured,
            'measured' => $selected->measured,
        ]);
    }

    public function catalogue(): TrustedModelCatalogue
    {
        $path = $this->cataloguePath ?? TrustedModelCatalogue::BUNDLED;

        return TrustedModelCatalogue::fromVerifiedFiles($path, TrustedModelCatalogue::checksumPath($path), new AdmissionPolicy);
    }

    /**
     * Why Molly would not install this candidate now, as codes. An empty list means the
     * installer may continue after the person authorizes any download.
     *
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private function blockers(ModelFitStatus $status, array $constraints, string $runtime, bool $stale): array
    {
        $blockers = $status->fits() ? [] : [$status->value];
        if ($stale) {
            $blockers[] = 'catalogue_stale';
        }
        if ($runtime !== 'verified') {
            $blockers[] = 'runtime_'.$runtime;
        }

        return [...$blockers, ...array_values(array_intersect($constraints, ['installed_digest_mismatch', 'base_digest_mismatch', 'memory_pressure_critical']))];
    }

    private function message(ModelFitRecommendation $selected, TrustedModelCatalogue $catalogue, InstallationHeadroom $headroom): string
    {
        $runtime = $catalogue->runtimes()[0] ?? ['minimum_os_version' => '?'];

        return match ($selected->status) {
            ModelFitStatus::RecommendedFit => $selected->modelId.' fits this Mac and leaves '.InstallationHeadroom::gigabytes($headroom->memoryBytes).' of memory headroom.'
                .(in_array('memory_held_by_loaded_model', $selected->constraints, true) ? ' Memory pressure is warning, but Ollama already holds '.$selected->modelId.', and the memory it holds counts as available to it.' : ''),
            ModelFitStatus::MinimumFit => $selected->modelId.' meets its minimum requirements on this Mac, with constraints: '.implode(', ', $selected->constraints).'.',
            ModelFitStatus::AlreadyInstalled => $selected->modelId.' is installed with the approved digest and passed Molly\'s readiness check on this runtime.',
            ModelFitStatus::NoFit => self::NO_FIT_MESSAGE,
            ModelFitStatus::MemoryUnavailable => $this->memoryUnavailable($selected, $headroom),
            ModelFitStatus::Unsupported => 'Molly\'s approved local Ollama configuration needs an Apple silicon Mac with macOS '.$runtime['minimum_os_version'].' or later. This machine does not qualify: '.implode(', ', $selected->reasons).'.',
            ModelFitStatus::Unknown => 'Molly could not decide whether a local Ollama configuration fits this Mac, because it could not measure: '.implode(', ', $selected->reasons).'. This is not a finding that the Mac is incompatible. Molly downloads nothing until the facts are measured.',
        };
    }

    /**
     * Why no model is selected when a run would refuse every fitting model now: the
     * memory a run needs before Ollama loads the model, and whether freeing memory can
     * provide it or this Mac's total memory is smaller than that.
     */
    private function memoryUnavailable(ModelFitRecommendation $selected, InstallationHeadroom $headroom): string
    {
        $size = (int) $selected->required['model_bytes'];
        $needs = 'a run needs '.InstallationHeadroom::gigabytes($size).' plus '.InstallationHeadroom::gigabytes($headroom->memoryBytes).' of headroom available before Ollama loads it';
        $total = $selected->measured['total_memory_bytes'] ?? null;
        $refused = 'Molly would refuse to load it with MODEL_MEMORY_INSUFFICIENT, so it selects no model.';

        return is_int($total) && ! $headroom->fitsMemory($size, $total)
            ? $selected->modelId.' meets its requirements on this Mac, but '.$needs.', which is more than this Mac\'s '.InstallationHeadroom::gigabytes($total).' of memory. '.$refused.' Only a model Ollama already holds can run here with molly.memory.headroom_gb at '.InstallationHeadroom::gigabytes($headroom->memoryBytes).'.'
            : $selected->modelId.' meets its requirements on this Mac, but '.$needs.', and '.InstallationHeadroom::gigabytes((int) ($selected->measured['available_memory_bytes'] ?? 0)).' is available now. '.$refused.' Free memory, then run molly:preflight again.';
    }

    /** @return array<string, mixed> */
    private function modelSelection(TrustedModelCatalogue $catalogue, ModelFitRecommendation $selected): array
    {
        $entry = $catalogue->approvedEntry((string) $selected->modelId);

        return [
            'model' => $selected->modelId,
            'digest' => 'sha256:'.$entry['artifact']['digest'],
            'size_bytes' => $entry['artifact']['size_bytes'],
            'download_bytes' => $selected->downloadBytes,
            'install_method' => $entry['artifact']['install']['method'],
            'install_from' => $entry['artifact']['install']['from']['name'] ?? null,
            'fit' => $selected->fit?->value,
            'installed' => $selected->installed,
            'verified' => $selected->verified,
            'runtime' => $selected->runtime,
        ];
    }

    /**
     * Every model Ollama lists, with Molly's admission for it. Molly never deletes,
     * replaces, or activates any of them.
     *
     * @return list<array{name: string, digest: string, size_bytes: int|null, admission: string, reasons: list<string>}>
     */
    private function installedModels(TrustedModelCatalogue $catalogue, MachineFacts $machine): array
    {
        return array_map(fn (array $model): array => $model + $this->admission($catalogue, $model['name'], $model['digest']), $machine->installedModels ?? []);
    }

    /** @return array{name: string, installed: bool|null, admission: string, reasons: list<string>}|null */
    private function configuredModel(TrustedModelCatalogue $catalogue, MachineFacts $machine, ?string $name): ?array
    {
        if ($name === null) {
            return null;
        }
        $installed = $machine->installed($name);

        return ['name' => $name, 'installed' => is_array($installed) ? true : ($installed === false ? false : null)]
            + (is_array($installed)
                ? $this->admission($catalogue, $name, $installed['digest'])
                : ($catalogue->approvedEntry($name) !== null ? ['admission' => 'approved', 'reasons' => []] : $this->admission($catalogue, $name, '')));
    }

    /** @return array{admission: string, reasons: list<string>} */
    private function admission(TrustedModelCatalogue $catalogue, string $name, string $digest): array
    {
        $entry = $catalogue->approvedEntry($name);
        if ($entry !== null) {
            return $catalogue->admitArtifact($name, $digest)->approved
                ? ['admission' => 'approved', 'reasons' => []]
                : ['admission' => 'digest_mismatch', 'reasons' => ['installed_digest_mismatch']];
        }
        foreach ($catalogue->approvedEntries() as $approved) {
            $from = $approved['artifact']['install']['from'] ?? null;
            if (is_array($from) && $from['name'] === $name && hash_equals($from['digest'], $digest)) {
                return ['admission' => 'build_input', 'reasons' => ['base_for:'.$approved['artifact']['name']]];
            }
        }
        $decision = $catalogue->decisionFor($name);

        return $decision->rejectionReasons === ['model_not_catalogued']
            ? ['admission' => 'not_catalogued', 'reasons' => ['model_not_catalogued']]
            : ['admission' => 'rejected', 'reasons' => $decision->rejectionReasons];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function document(MachineFacts $machine, array $inputs, array $decision): array
    {
        $document = ['schema' => self::SCHEMA, 'snapshot_sha256' => $machine->snapshotSha256, 'inputs' => $inputs] + $decision;

        return $document + ['decision_sha256' => HardwareSnapshot::hash($document)];
    }
}

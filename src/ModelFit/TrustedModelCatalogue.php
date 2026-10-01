<?php

namespace Sifrious\Molly\ModelFit;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * The approved local model catalogue that ships with Molly, checked against its
 * SHA-256 file and re-evaluated with AdmissionPolicy on every load. The pinned
 * runtimes are kept apart from the model entries, so a runtime version is selected
 * separately from a model. Loading fails with a coded message when the file was
 * changed, was written for another policy version, or breaks the contract.
 */
final readonly class TrustedModelCatalogue
{
    public const BUNDLED = __DIR__.'/../../resources/models/catalogue.v1.json';

    /**
     * @param  list<array<string, mixed>>  $runtimes
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, AdmissionDecision>  $decisions
     */
    private function __construct(
        public string $catalogueVersion,
        public string $policyVersion,
        public string $catalogueDigest,
        public string $reviewedAt,
        public string $reviewBy,
        private array $runtimes,
        private array $entries,
        private array $decisions,
    ) {}

    public static function bundled(): self
    {
        return self::fromVerifiedFiles(self::BUNDLED, self::checksumPath(self::BUNDLED), new AdmissionPolicy);
    }

    /** The checksum file that sits beside a catalogue: catalogue.v1.json has catalogue.v1.sha256. */
    public static function checksumPath(string $cataloguePath): string
    {
        return (string) preg_replace('/\.json$/', '', $cataloguePath).'.sha256';
    }

    public static function fromVerifiedFiles(
        string $cataloguePath,
        string $checksumPath,
        AdmissionPolicy $policy,
    ): self {
        $json = is_file($cataloguePath) ? file_get_contents($cataloguePath) : false;
        $checksum = is_file($checksumPath) ? trim((string) file_get_contents($checksumPath)) : '';
        if ($json === false || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new RuntimeException('CATALOGUE_UNREADABLE: The model catalogue or its SHA-256 checksum is unreadable.');
        }

        $actualChecksum = hash('sha256', $json);
        if (! hash_equals($checksum, $actualChecksum)) {
            throw new RuntimeException('CATALOGUE_INTEGRITY_FAILED: The model catalogue failed integrity verification.');
        }

        try {
            $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('CATALOGUE_INVALID: The model catalogue is not valid JSON.', previous: $exception);
        }

        if (! is_array($document)
            || ! is_string($document['catalogue_version'] ?? null)
            || ! is_array($document['entries'] ?? null)
            || ! is_array($document['runtimes'] ?? null)
            || ! self::isDate($document['reviewed_at'] ?? null)
            || ! self::isDate($document['review_by'] ?? null)
        ) {
            throw new RuntimeException('CATALOGUE_INVALID: The model catalogue contract is invalid.');
        }

        if (($document['policy_version'] ?? null) !== AdmissionPolicy::VERSION) {
            throw new RuntimeException('CATALOGUE_POLICY_STALE: The model catalogue was written for admission policy '.json_encode($document['policy_version'] ?? null).', and Molly applies policy '.AdmissionPolicy::VERSION.'.');
        }

        $runtimes = [];
        foreach ($document['runtimes'] as $runtime) {
            if (! is_array($runtime)
                || ! is_string($runtime['runtime'] ?? null) || $runtime['runtime'] === ''
                || ! is_string($runtime['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/D', $runtime['version']) !== 1
                || ! is_string($runtime['minimum_os_version'] ?? null) || preg_match('/^\d+(\.\d+)*$/D', $runtime['minimum_os_version']) !== 1
                || ! is_array($runtime['platforms'] ?? null) || $runtime['platforms'] === []
                || ! is_string($runtime['source'] ?? null) || ! str_starts_with($runtime['source'], 'https://')
            ) {
                throw new RuntimeException('CATALOGUE_INVALID: A pinned runtime has no name, exact version, minimum macOS version, platform, or HTTPS source.');
            }
            $runtimes[] = $runtime;
        }

        $entries = [];
        $decisions = [];
        foreach ($document['entries'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['identity']['model_id'] ?? null)) {
                throw new RuntimeException('CATALOGUE_INVALID: A model catalogue entry has no stable identity.');
            }

            $decision = $policy->evaluate($entry);
            $declaredDecision = $entry['admission']['decision'] ?? null;
            $declaredReasons = $entry['admission']['rejection_reasons'] ?? null;
            if ($declaredDecision !== ($decision->approved ? 'approved' : 'rejected')
                || $declaredReasons !== $decision->rejectionReasons
            ) {
                throw new RuntimeException("CATALOGUE_INVALID: The declared admission decision for {$entry['identity']['model_id']} does not match policy.");
            }

            $modelId = $entry['identity']['model_id'];
            if (array_key_exists($modelId, $decisions)) {
                throw new RuntimeException("CATALOGUE_INVALID: Duplicate model identity in catalogue: $modelId.");
            }
            if ($decision->approved) {
                foreach ($entry['runtime_compatibility'] as $compatibility) {
                    if (self::pinned($runtimes, $compatibility['runtime'], $compatibility['version_constraint']) === null) {
                        throw new RuntimeException("CATALOGUE_INVALID: $modelId names runtime {$compatibility['runtime']} {$compatibility['version_constraint']}, which the catalogue does not pin.");
                    }
                }
            }
            $entries[] = $entry;
            $decisions[$modelId] = $decision;
        }

        return new self(
            $document['catalogue_version'],
            $document['policy_version'],
            "sha256:$actualChecksum",
            $document['reviewed_at'],
            $document['review_by'],
            $runtimes,
            $entries,
            $decisions,
        );
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<array<string, mixed>> */
    public function approvedEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (array $entry): bool => $this->decisions[$entry['identity']['model_id']]->approved,
        ));
    }

    /** @return array<string, mixed>|null */
    public function approvedEntry(string $modelId): ?array
    {
        foreach ($this->approvedEntries() as $entry) {
            if ($entry['identity']['model_id'] === $modelId) {
                return $entry;
            }
        }

        return null;
    }

    public function decisionFor(string $modelId): AdmissionDecision
    {
        return $this->decisions[$modelId] ?? new AdmissionDecision(false, ['model_not_catalogued']);
    }

    /** @return list<array<string, mixed>> */
    public function runtimes(): array
    {
        return $this->runtimes;
    }

    /** @return array<string, mixed>|null The pinned runtime a model entry names. */
    public function runtimeFor(array $compatibility): ?array
    {
        return self::pinned($this->runtimes, (string) ($compatibility['runtime'] ?? ''), (string) ($compatibility['version_constraint'] ?? ''));
    }

    /**
     * Whether the review date has passed on the given day. A stale catalogue still
     * describes what fits, but Molly downloads nothing from it.
     */
    public function staleOn(string $date): bool
    {
        return substr($date, 0, 10) > $this->reviewBy;
    }

    /**
     * The installer boundary: only the exact approved Ollama name and manifest digest
     * are admitted.
     */
    public function admitArtifact(string $name, string $sha256Digest): AdmissionDecision
    {
        foreach ($this->approvedEntries() as $entry) {
            if (hash_equals($entry['artifact']['name'], $name)
                && hash_equals($entry['artifact']['digest'], strtolower((string) preg_replace('/^sha256:/', '', $sha256Digest)))
            ) {
                return new AdmissionDecision(true, []);
            }
        }

        return new AdmissionDecision(false, ['artifact_source_or_digest_not_approved']);
    }

    /** @return array<string, string>|null */
    public function provenanceFor(string $modelId): ?array
    {
        $entry = $this->approvedEntry($modelId);
        if ($entry === null) {
            return null;
        }

        return [
            'catalogue_version' => $this->catalogueVersion,
            'catalogue_digest' => $this->catalogueDigest,
            'policy_version' => $this->policyVersion,
            'model_id' => $entry['identity']['model_id'],
            'model_version' => $entry['identity']['model_version'],
            'upstream_revision' => $entry['identity']['upstream_revision'],
            'artifact_source' => $entry['artifact']['source'],
            'artifact_digest' => "sha256:{$entry['artifact']['digest']}",
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runtimes
     * @return array<string, mixed>|null
     */
    private static function pinned(array $runtimes, string $name, string $version): ?array
    {
        foreach ($runtimes as $runtime) {
            if ($runtime['runtime'] === $name && $runtime['version'] === $version) {
                return $runtime;
            }
        }

        return null;
    }

    private static function isDate(mixed $value): bool
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

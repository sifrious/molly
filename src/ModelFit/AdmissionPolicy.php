<?php

namespace Sifrious\Molly\ModelFit;

use DateTimeImmutable;

/**
 * Decides whether a catalogue entry may be offered at all. An entry is approved only
 * when every required piece of evidence is present: a US-developed model from a US
 * organization, an HTTPS source, a SHA-256 digest and size, a licence that permits
 * local and commercial use, a pinned runtime, memory requirements, and a review date.
 * Anything missing or unknown is a rejection reason, so an entry with an unknown
 * origin is never approved and never downloaded.
 */
final readonly class AdmissionPolicy
{
    public const VERSION = '1.0.0';

    /**
     * Molly asks for structured output through Laravel AI. Harmony is one model family's
     * response format, so it is recorded in the catalogue but not required.
     *
     * @var list<string>
     */
    private const REQUIRED_CAPABILITIES = [
        'tool_calling',
        'function_calling',
        'structured_outputs',
    ];

    /** @param array<string, mixed> $entry */
    public function evaluate(array $entry): AdmissionDecision
    {
        $reasons = [];

        $this->requireValue($entry, 'identity.model_id', $reasons);
        $this->requireValue($entry, 'identity.model_version', $reasons);
        $this->requireValue($entry, 'identity.upstream_revision', $reasons);
        $this->requireValue($entry, 'developer.organization', $reasons);
        $this->requireValue($entry, 'developer.organization_country', $reasons);
        $this->requireUriList($entry, 'developer.origin_evidence', $reasons);
        $this->requireDate($entry, 'review.reviewed_at', $reasons);
        $this->requireUriList($entry, 'review.evidence', $reasons);

        if ($this->value($entry, 'developer.origin') !== 'us-developed') {
            $reasons[] = 'origin_not_us_developed';
        }

        if ($this->value($entry, 'developer.organization_country') !== 'US') {
            $reasons[] = 'developer_organization_not_us';
        }

        $this->requireValue($entry, 'artifact.name', $reasons);
        $source = $this->value($entry, 'artifact.source');
        if (! is_string($source) || filter_var($source, FILTER_VALIDATE_URL) === false || ! str_starts_with($source, 'https://')) {
            $reasons[] = 'artifact_source_not_trusted_https';
        }

        if ($this->value($entry, 'artifact.digest_algorithm') !== 'sha256') {
            $reasons[] = 'artifact_digest_algorithm_not_sha256';
        }

        if (! $this->isDigest($this->value($entry, 'artifact.digest'))) {
            $reasons[] = 'artifact_digest_invalid';
        }

        if (! is_int($this->value($entry, 'artifact.size_bytes')) || $this->value($entry, 'artifact.size_bytes') < 1) {
            $reasons[] = 'artifact_size_unknown';
        }

        if (! $this->isDigest($this->value($entry, 'artifact.weights.digest'))
            || ! is_int($this->value($entry, 'artifact.weights.size_bytes'))
            || $this->value($entry, 'artifact.weights.size_bytes') < 1) {
            $reasons[] = 'artifact_weights_unknown';
        }

        if (! $this->validInstall($this->value($entry, 'artifact.install'))) {
            $reasons[] = 'artifact_install_invalid';
        }

        $this->requireValue($entry, 'license.spdx_id', $reasons);
        $this->requireValue($entry, 'license.version', $reasons);
        $this->requireUri($entry, 'license.source', $reasons);
        $this->requireUri($entry, 'license.usage_policy_source', $reasons);
        $this->requireKnownPermission($entry, 'license.commercial_use', $reasons);
        $this->requireKnownPermission($entry, 'license.local_use', $reasons);
        $this->requireKnownPermission($entry, 'license.redistribution', $reasons);
        $this->requireKnownPermission($entry, 'license.hosted_commercial_use', $reasons);

        if ($this->value($entry, 'license.commercial_use') !== 'permitted') {
            $reasons[] = 'commercial_use_not_permitted';
        }

        if ($this->value($entry, 'license.local_use') !== 'permitted') {
            $reasons[] = 'local_use_not_permitted';
        }

        if ($this->value($entry, 'license.redistribution') !== 'permitted') {
            $reasons[] = 'redistribution_not_permitted';
        }

        if (! $this->isNonEmptyStringList($this->value($entry, 'license.notice_requirements'), allowEmpty: true)) {
            $reasons[] = 'notice_requirements_unknown';
        }
        if (! $this->isNonEmptyStringList($this->value($entry, 'license.constraints'))) {
            $reasons[] = 'license_constraints_unknown';
        }

        $runtimes = $this->value($entry, 'runtime_compatibility');
        if (! is_array($runtimes) || $runtimes === []) {
            $reasons[] = 'supported_runtime_unknown';
        } else {
            foreach ($runtimes as $runtime) {
                if (! is_array($runtime)
                    || ! $this->isNonEmptyString($runtime['runtime'] ?? null)
                    || ! $this->isNonEmptyString($runtime['version_constraint'] ?? null)
                    || ! $this->isNonEmptyStringList($runtime['platforms'] ?? null)
                    || ! $this->isNonEmptyStringList($runtime['requirements'] ?? null, allowEmpty: true)
                ) {
                    $reasons[] = 'runtime_compatibility_invalid';
                    break;
                }
            }
        }

        $minimumMemory = $this->value($entry, 'hardware.minimum_memory_bytes');
        $recommendedMemory = $this->value($entry, 'hardware.recommended_memory_bytes');
        if (! is_int($minimumMemory) || $minimumMemory < 1) {
            $reasons[] = 'minimum_memory_unknown';
        }
        if (! is_int($recommendedMemory)
            || ! is_int($minimumMemory)
            || $recommendedMemory < $minimumMemory
        ) {
            $reasons[] = 'recommended_memory_invalid';
        }
        if (! $this->isNonEmptyStringList($this->value($entry, 'hardware.requirements'))) {
            $reasons[] = 'hardware_requirements_unknown';
        }
        if (! $this->isNonEmptyStringList($this->value($entry, 'hardware.recommended'))) {
            $reasons[] = 'recommended_hardware_unknown';
        }

        $contextWindow = $this->value($entry, 'capabilities.context_window_tokens');
        if (! is_int($contextWindow) || $contextWindow < 1) {
            $reasons[] = 'context_window_unknown';
        }
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            if ($this->value($entry, "capabilities.$capability") !== true) {
                $reasons[] = "required_capability_missing:$capability";
            }
        }

        foreach (['local', 'byo_cloud', 'harness_managed_cloud'] as $mode) {
            $this->requireKnownPermission($entry, "execution_modes.$mode", $reasons);
        }
        if ($this->value($entry, 'execution_modes.local') !== 'permitted') {
            $reasons[] = 'local_execution_not_permitted';
        }
        if ($this->value($entry, 'license.hosted_commercial_use') !== 'permitted'
            && ($this->value($entry, 'execution_modes.byo_cloud') === 'permitted'
                || $this->value($entry, 'execution_modes.harness_managed_cloud') === 'permitted')
        ) {
            $reasons[] = 'hosted_execution_exceeds_license';
        }

        return new AdmissionDecision($reasons === [], array_values(array_unique($reasons)));
    }

    /**
     * An Ollama artifact is either pulled by name, or derived from a pinned base model
     * with fixed parameters, as `ollama create` does from a Modelfile.
     */
    private function validInstall(mixed $install): bool
    {
        if (! is_array($install)) {
            return false;
        }

        return match ($install['method'] ?? null) {
            'pull' => true,
            'derive' => is_array($install['from'] ?? null)
                && $this->isNonEmptyString($install['from']['name'] ?? null)
                && $this->isDigest($install['from']['digest'] ?? null)
                && is_int($install['from']['size_bytes'] ?? null) && $install['from']['size_bytes'] > 0
                && is_array($install['parameters'] ?? null) && $install['parameters'] !== [] && ! array_is_list($install['parameters']),
            default => false,
        };
    }

    private function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @param array<string, mixed> $entry
     * @param  list<string>  $reasons
     */
    private function requireValue(array $entry, string $path, array &$reasons): void
    {
        $value = $this->value($entry, $path);
        if ($value === null || $value === '' || $value === []) {
            $reasons[] = "missing_required_evidence:$path";
        }
    }

    /** @param array<string, mixed> $entry
     * @param  list<string>  $reasons
     */
    private function requireKnownPermission(array $entry, string $path, array &$reasons): void
    {
        if (! in_array($this->value($entry, $path), ['permitted', 'prohibited'], true)) {
            $reasons[] = "unknown_permission:$path";
        }
    }

    /** @param array<string, mixed> $entry
     * @param  list<string>  $reasons
     */
    private function requireUri(array $entry, string $path, array &$reasons): void
    {
        $value = $this->value($entry, $path);
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $reasons[] = "invalid_evidence_uri:$path";
        }
    }

    /** @param array<string, mixed> $entry
     * @param  list<string>  $reasons
     */
    private function requireUriList(array $entry, string $path, array &$reasons): void
    {
        $values = $this->value($entry, $path);
        if (! is_array($values) || $values === []) {
            $reasons[] = "missing_required_evidence:$path";

            return;
        }
        foreach ($values as $value) {
            if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                $reasons[] = "invalid_evidence_uri:$path";

                return;
            }
        }
    }

    /** @param array<string, mixed> $entry
     * @param  list<string>  $reasons
     */
    private function requireDate(array $entry, string $path, array &$reasons): void
    {
        $value = $this->value($entry, $path);
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $reasons[] = "invalid_date:$path";
        }
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isNonEmptyStringList(mixed $value, bool $allowEmpty = false): bool
    {
        if (! is_array($value) || (! $allowEmpty && $value === [])) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isNonEmptyString($item)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $entry */
    private function value(array $entry, string $path): mixed
    {
        $value = $entry;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

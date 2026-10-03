<?php

namespace Sifrious\Molly\Acceptance;

use JsonException;
use RuntimeException;

/**
 * Proves the Bloom checks that can be read from the plugin seam and shared
 * state. A visible macOS assertion stays needs_native so a missing permission
 * can block that observation without hiding a product failure.
 */
final class RepoBloomCheckProbe
{
    public function probe(VerificationCheckDefinition $check, VerificationContext $context): ProbeResult
    {
        if (in_array($check->id, $context->harnessBroken, true)) {
            throw new RuntimeException('PROBE_BROKEN: The verification fixture for '.$check->id.' could not run.');
        }
        if (isset($context->observations[$check->id])) {
            return ProbeResult::fromArray($context->observations[$check->id]);
        }

        return match ($check->id) {
            'M04.1' => $this->compiledHost($context),
            'M04.9' => $this->settings($context),
            'M04.12' => $this->cliVisibility($context),
            'M04.16' => $this->restart($context),
            'M04.2', 'M04.3', 'M04.10' => ProbeResult::needsNative(
                'NATIVE_UI_REQUIRED',
                $check->requirement.' has to be driven in the Bloom UI. Accessibility is the verifier permission for that observation.',
            ),
            default => ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'EVIDENCE_NOT_OBSERVED',
                'No deterministic '.$check->id.' observation was recorded, so Molly did not call the check a pass.',
            ),
        };
    }

    private function compiledHost(VerificationContext $context): ProbeResult
    {
        $seam = $this->seam($context->repoRoot);
        if ($seam !== null) {
            return $seam;
        }
        if ($this->hostDiscovered($context)) {
            return ProbeResult::needsNative(
                'HOST_UI_NOT_OBSERVED',
                'The extension seam matches and host telemetry says sifrious.molly was discovered. Nav visibility still needs Screen Recording.',
            );
        }

        return ProbeResult::complete(
            CheckOutcome::BlockedPrerequisite,
            'COMPILED_HOST_NOT_OBSERVED',
            'The extension seam matches. The compiled Bloom host has not been observed, so M04.1 is not accepted and is not a Molly product failure.',
        );
    }

    private function settings(VerificationContext $context): ProbeResult
    {
        if (! array_key_exists('settings_match', $context->sharedState)) {
            return ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'SETTINGS_COMPARISON_NOT_OBSERVED',
                'Molly has no displayed settings and effective run configuration to compare.',
            );
        }
        if ($context->sharedState['settings_match'] === true) {
            return ProbeResult::complete(CheckOutcome::Pass, 'SETTINGS_MATCH', 'Displayed settings match the run effective configuration.');
        }

        return ProbeResult::complete(
            CheckOutcome::ProductFail,
            'SETTINGS_MISMATCH',
            'Displayed settings do not match the run effective configuration.',
        );
    }

    private function cliVisibility(VerificationContext $context): ProbeResult
    {
        if (! array_key_exists('cli_task_visible', $context->sharedState)) {
            return ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'CLI_TO_BLOOM_STATE_NOT_OBSERVED',
                'Molly has no shared project state showing whether a CLI task is visible to Bloom.',
            );
        }
        if ($context->sharedState['cli_task_visible'] === true) {
            return ProbeResult::complete(CheckOutcome::Pass, 'CLI_STATE_VISIBLE', 'The CLI task is in the project state Bloom reads.');
        }

        return ProbeResult::complete(CheckOutcome::ProductFail, 'CLI_STATE_HIDDEN', 'The CLI task is missing from the project state Bloom reads.');
    }

    private function restart(VerificationContext $context): ProbeResult
    {
        if (($context->sharedState['restart_needs_screenshot'] ?? false) === true) {
            return ProbeResult::needsNative(
                'RESTART_UI_NOT_OBSERVED',
                'Persisted state matches. The screenshot of that state still needs Screen Recording.',
            );
        }
        if (! array_key_exists('restart_persisted', $context->sharedState)) {
            return ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'RESTART_PERSISTENCE_NOT_OBSERVED',
                'Molly has no restart persistence record for this candidate.',
            );
        }
        if ($context->sharedState['restart_persisted'] === true) {
            return ProbeResult::complete(CheckOutcome::Pass, 'RESTART_PERSISTED', 'The same task state was recorded after the host restart.');
        }

        return ProbeResult::complete(CheckOutcome::ProductFail, 'RESTART_STATE_LOST', 'The task state after restart does not match the state recorded before it.');
    }

    private function seam(string $root): ?ProbeResult
    {
        $manifest = $root.'/bloom-plugin/plugin.json';
        $provider = $root.'/bloom-plugin/Surfaces/Sources/MollySurfaces/MollySurfaceProvider.swift';
        if (! is_file($manifest) || ! is_file($provider)) {
            return ProbeResult::complete(CheckOutcome::ProductFail, 'PLUGIN_SEAM_MISSING', 'The Bloom plugin manifest or surface provider is missing.');
        }
        try {
            $json = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ProbeResult::complete(CheckOutcome::ProductFail, 'PLUGIN_MANIFEST_INVALID', 'bloom-plugin/plugin.json is not valid JSON.');
        }
        if (! is_array($json) || ($json['id'] ?? null) !== 'sifrious.molly' || ($json['apiVersion'] ?? null) !== 1) {
            return ProbeResult::complete(CheckOutcome::ProductFail, 'PLUGIN_MANIFEST_UNEXPECTED', 'plugin.json must declare id sifrious.molly and apiVersion 1.');
        }
        $nav = array_map(fn (mixed $item): string => is_array($item) ? (string) ($item['id'] ?? '') : '', $json['nav'] ?? []);
        preg_match_all('/\("([a-z0-9.]+)",/', (string) file_get_contents($provider), $matches);
        if ($nav === [] || $nav !== $matches[1]) {
            return ProbeResult::complete(
                CheckOutcome::ProductFail,
                'PLUGIN_SEAM_MISMATCH',
                'plugin.json nav ids do not match the surfaces MollySurfaceProvider registers.',
            );
        }

        return null;
    }

    private function hostDiscovered(VerificationContext $context): bool
    {
        if ($context->hostTelemetryPath === null || ! is_file($context->hostTelemetryPath)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($context->hostTelemetryPath), true);

        return is_array($decoded)
            && ($decoded['plugin_discovered'] ?? false) === true
            && ($decoded['plugin_id'] ?? null) === 'sifrious.molly';
    }
}

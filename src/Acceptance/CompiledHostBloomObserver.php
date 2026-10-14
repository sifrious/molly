<?php

namespace Sifrious\Molly\Acceptance;

use Sifrious\Molly\Actions\ListMollyProjects;
use Throwable;

/**
 * Reads a compiled-host observation the host already wrote.
 * A caller-supplied PASS record is not that observation.
 * M04.2 also reads a source-new project, because New Project records that app.
 */
class CompiledHostBloomObserver
{
    public int $calls = 0;

    public function __construct(private ListMollyProjects $projects) {}

    public function observe(VerificationCheckDefinition $check, VerificationContext $context): ProbeResult
    {
        $this->calls++;
        $root = $this->normalize($context->projectRoot);
        $primary = $root === '' ? null : $this->read($check, $context, $root);
        if ($primary !== null) {
            return $primary;
        }
        $stale = null;
        foreach ($this->newProjects($check, $root) as $directory) {
            $result = $this->read($check, $context, $directory);
            if ($result === null) {
                continue;
            }
            if ($result->reasonCode === 'STALE_NATIVE_EVIDENCE') {
                $stale ??= $result;

                continue;
            }

            return $result;
        }

        return $stale ?? $this->missing($check);
    }

    /** @return list<string> */
    private function newProjects(VerificationCheckDefinition $check, string $root): array
    {
        if ($check->id !== 'M04.2') {
            return [];
        }
        try {
            $listed = $this->projects->handle();
        } catch (Throwable) {
            return [];
        }
        $directories = [];
        foreach ($listed as $project) {
            if ($project->source !== 'new') {
                continue;
            }
            $path = $this->normalize($project->path);
            if ($path !== '' && $path !== $root) {
                $directories[] = $path;
            }
        }

        return $directories;
    }

    private function read(VerificationCheckDefinition $check, VerificationContext $context, string $directory): ?ProbeResult
    {
        $recordPath = $directory.'/acceptance-native/'.$check->id.'.json';
        if (! is_file($recordPath)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($recordPath), true);
        if (! is_array($decoded)
            || ! is_string($decoded['candidate_sha'] ?? null)
            || ! is_string($decoded['assertion'] ?? null)
            || ! is_string($decoded['expected'] ?? null)
            || ! is_string($decoded['actual'] ?? null)
            || ! is_string($decoded['source_artifact'] ?? null)) {
            return null;
        }
        if ($decoded['candidate_sha'] !== $context->candidateSha) {
            return ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'STALE_NATIVE_EVIDENCE',
                $check->id.' has a compiled-host record for a different candidate. Molly did not reuse it.',
            );
        }
        $artifactName = $decoded['source_artifact'];
        if ($artifactName === '' || basename($artifactName) !== $artifactName) {
            return null;
        }
        $source = $directory.'/acceptance-native/'.$artifactName;
        if (! is_file($source)) {
            return null;
        }
        $observed = json_decode((string) file_get_contents($source), true);
        if (! is_array($observed)
            || ($observed['candidate_sha'] ?? null) !== $decoded['candidate_sha']
            || ($observed['actual'] ?? null) !== $decoded['actual']) {
            return null;
        }
        $relative = 'acceptance-native/'.$decoded['source_artifact'];
        $located = $directory === $this->normalize($context->projectRoot) ? $relative : $directory.'/'.$relative;
        $assertion = new CheckAssertion(
            $context->candidateSha,
            $context->runId,
            $decoded['assertion'],
            $decoded['expected'],
            $decoded['actual'],
            $located,
            $decoded['expected'] === $decoded['actual'] ? 'pass' : 'fail',
        );
        if ($assertion->result === 'fail') {
            return ProbeResult::complete(
                CheckOutcome::ProductFail,
                'NATIVE_OBSERVATION_MISMATCH',
                $check->id.' compiled-host observation expected '.$assertion->expected.' and saw '.$assertion->actual.'.',
                [$assertion->sourceArtifact],
                [$assertion],
            );
        }

        return ProbeResult::complete(
            CheckOutcome::Pass,
            'NATIVE_OBSERVATION_MATCHED',
            $check->id.' compiled-host observation matched '.$assertion->sourceArtifact.'.',
            [$assertion->sourceArtifact],
            [$assertion],
        );
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }
        $real = realpath($path);

        return $real !== false ? rtrim(str_replace('\\', '/', $real), '/') : $path;
    }

    private function missing(VerificationCheckDefinition $check): ProbeResult
    {
        return ProbeResult::complete(
            CheckOutcome::BlockedPrerequisite,
            'COMPILED_HOST_NOT_OBSERVED',
            $check->id.' was allowed to observe the Bloom UI, and no compiled host observation was recorded. This is not a Molly product failure.',
        );
    }
}

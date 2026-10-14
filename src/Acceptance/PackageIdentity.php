<?php

namespace Sifrious\Molly\Acceptance;

/**
 * The installed sifrious/molly package molly:verify is actually running.
 * commit is null when neither an injected candidate nor a clean git HEAD proves it.
 */
final readonly class PackageIdentity
{
    public function __construct(
        public ?string $commit,
        public string $source,
        public ?string $version,
        public ?string $reference,
        public ?string $installPath,
        public bool $symlinked,
        public ?string $distShasumSha1,
        public ?string $lockSha256,
        public ?string $candidateArtifactSha256,
    ) {}

    /** @return array<string, bool|string|null> */
    public function toArray(): array
    {
        return [
            'commit' => $this->commit,
            'source' => $this->source,
            'version' => $this->version,
            'reference' => $this->reference,
            'install_path' => $this->installPath,
            'symlinked' => $this->symlinked,
            'dist_shasum_sha1' => $this->distShasumSha1,
            'lock_sha256' => $this->lockSha256,
            'candidate_artifact_sha256' => $this->candidateArtifactSha256,
        ];
    }
}

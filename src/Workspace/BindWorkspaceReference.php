<?php

namespace Sifrious\Molly\Workspace;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mint Molly-owned identities for a checkout observation.
 * Paths are never used as canonical IDs — UUIDs are minted; observation supplies revision metadata.
 */
final class BindWorkspaceReference
{
    public function __construct(private ObserveCheckout $observe) {}

    public function handle(string $path, ?string $bloomWorkspaceId = null): WorkspaceReference
    {
        $obs = $this->observe->handle($path);

        if ($obs['head'] === null || ! preg_match('/\A[0-9a-f]{40}\z/', $obs['head'])) {
            throw new RuntimeException('WORKSPACE_REVISION_MISSING: Checkout has no usable HEAD revision.');
        }

        $gitMeta = $obs['path'].'/.git';
        $checkoutKind = is_file($gitMeta) ? 'worktree' : (is_dir($gitMeta) ? 'clone' : 'unknown');

        return new WorkspaceReference(
            project: ProjectIdentity::mint(),
            workspace: WorkspaceIdentity::mint(),
            repositoryId: (string) Str::uuid(),
            repositoryRemoteIdentity: $obs['remote_identity'],
            checkoutId: (string) Str::uuid(),
            checkoutKind: $checkoutKind,
            availability: 'available',
            currentPath: $obs['path'],
            branch: $obs['branch'],
            head: RevisionIdentity::fromString($obs['head']),
            bloomWorkspaceId: $bloomWorkspaceId,
        );
    }
}

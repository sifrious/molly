<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;
use Sifrious\Molly\Projects\ProjectRegistry;

/**
 * Bind a checkout observation to canonical Molly identities.
 *
 * The same checkout root always yields the same project, workspace, repository, and
 * checkout IDs, read from `.molly/identity.json`. A registered project supplies its
 * registry ID as the project ID. Paths are never IDs; the observation supplies the
 * revision and branch for this call.
 */
final class BindWorkspaceReference
{
    public function __construct(
        private ObserveCheckout $observe,
        private ProjectRegistry $registry,
    ) {}

    public function handle(string $path, ?string $bloomWorkspaceId = null): WorkspaceReference
    {
        $obs = $this->observe->handle($path);

        if ($obs['head'] === null || ! preg_match('/\A[0-9a-f]{40}\z/', $obs['head'])) {
            throw new RuntimeException('WORKSPACE_REVISION_MISSING: '.$obs['path'].' has no commit yet. Commit your work, then try again.');
        }

        $gitMeta = $obs['path'].'/.git';
        $checkoutKind = is_file($gitMeta) ? 'worktree' : (is_dir($gitMeta) ? 'clone' : 'unknown');

        $root = realpath($obs['path']);
        $identity = $this->registry->checkoutIdentity($root === false ? $obs['path'] : $root);

        return new WorkspaceReference(
            project: ProjectIdentity::fromString($identity['project_id']),
            workspace: WorkspaceIdentity::fromString($identity['workspace_id']),
            repositoryId: $identity['repository_id'],
            repositoryRemoteIdentity: $obs['remote_identity'],
            checkoutId: $identity['checkout_id'],
            checkoutKind: $checkoutKind,
            availability: 'available',
            currentPath: $obs['path'],
            branch: $obs['branch'],
            head: RevisionIdentity::fromString($obs['head']),
            bloomWorkspaceId: $bloomWorkspaceId,
        );
    }
}

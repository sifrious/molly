<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Workspace\BindWorkspaceReference;
use Sifrious\Molly\Workspace\ObserveCheckout;
use Sifrious\Molly\Workspace\ProjectIdentity;
use Sifrious\Molly\Workspace\Provenance;
use Sifrious\Molly\Workspace\RevisionIdentity;
use Sifrious\Molly\Workspace\WorkspaceIdentity;
use Sifrious\Molly\Workspace\WorkspaceReference;

it('rejects path-like project identity', function () {
    expect(fn () => new ProjectIdentity('/tmp/not-a-uuid'))
        ->toThrow(InvalidArgumentException::class);
});

it('mints uuid project and workspace identities', function () {
    $project = ProjectIdentity::mint();
    $workspace = WorkspaceIdentity::mint();
    expect($project->id)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($workspace->id)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($project->id)->not->toBe($workspace->id);
});

it('requires a forty character revision', function () {
    expect(fn () => RevisionIdentity::fromString('abc'))
        ->toThrow(InvalidArgumentException::class);
    expect(RevisionIdentity::fromString(str_repeat('a', 40))->sha)->toBe(str_repeat('a', 40));
});

it('round-trips workspace reference json without treating path as id', function () {
    $reference = new WorkspaceReference(
        project: ProjectIdentity::mint(),
        workspace: WorkspaceIdentity::mint(),
        repositoryId: (string) Str::uuid(),
        repositoryRemoteIdentity: 'github:sifrious/molly',
        checkoutId: (string) Str::uuid(),
        checkoutKind: 'clone',
        availability: 'available',
        currentPath: '/tmp/observed-path-only',
        branch: 'main',
        head: RevisionIdentity::fromString(str_repeat('b', 40)),
    );

    $restored = WorkspaceReference::fromArray($reference->toArray());
    expect($restored->workspace->id)->toBe($reference->workspace->id)
        ->and($restored->currentPath)->toBe('/tmp/observed-path-only')
        ->and($restored->workspace->id)->not->toBe('/tmp/observed-path-only');
});

it('captures provenance only when available with a head', function () {
    $reference = new WorkspaceReference(
        project: ProjectIdentity::mint(),
        workspace: WorkspaceIdentity::mint(),
        repositoryId: (string) Str::uuid(),
        repositoryRemoteIdentity: null,
        checkoutId: (string) Str::uuid(),
        checkoutKind: 'clone',
        availability: 'ambiguous',
        currentPath: '/tmp/x',
        branch: null,
        head: null,
    );
    expect(fn () => Provenance::capture($reference))->toThrow(InvalidArgumentException::class);
});

it('observes git head from a checkout without using path as identity', function () {
    // A temporary checkout, never the Testbench skeleton at base_path().
    $checkout = sys_get_temp_dir().'/molly-observe-'.Str::uuid();
    File::ensureDirectoryExists($checkout);
    File::put($checkout.'/README.md', "Example\n");
    $head = commitGitWorkspace($checkout);

    try {
        $obs = app(ObserveCheckout::class)->handle($checkout);
        expect($obs['path'])->toBe($checkout)
            ->and($obs['head'])->toBe($head);

        $bound = app(BindWorkspaceReference::class)->handle($checkout);
        expect($bound->availability)->toBe('available')
            ->and($bound->head?->sha)->toBe($obs['head'])
            ->and($bound->workspace->id)->not->toBe($bound->currentPath)
            ->and($bound->project->id)->not->toBe($bound->currentPath);

        $again = app(BindWorkspaceReference::class)->handle($checkout);
        // The same checkout binds to the same stored IDs; the path is still never the ID.
        expect($again->workspace->id)->toBe($bound->workspace->id)
            ->and($again->project->id)->toBe($bound->project->id)
            ->and($again->currentPath)->toBe($bound->currentPath);
    } finally {
        File::deleteDirectory($checkout);
    }
});

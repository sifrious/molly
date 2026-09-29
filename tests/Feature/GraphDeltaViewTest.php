<?php

use Sifrious\Molly\GraphDelta\ChangeItemView;
use Sifrious\Molly\GraphDelta\ChangeType;
use Sifrious\Molly\GraphDelta\DeltaProvenance;
use Sifrious\Molly\GraphDelta\Freshness;
use Sifrious\Molly\GraphDelta\GraphDeltaView;
use Sifrious\Molly\GraphDelta\Section;
use Sifrious\Molly\GraphDelta\VerificationStatus;

function fixtureJson(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/GraphDelta/role-feature-delta.json');
}

function fixtureDelta(): GraphDeltaView
{
    return GraphDeltaView::fromJson(fixtureJson());
}

it('round-trips the fixture to identical JSON', function () {
    $original = json_decode(fixtureJson(), true);
    $view = GraphDeltaView::fromArray($original);
    $serialized = $view->toArray();

    expect($serialized)->toBe($original);
});

it('uses the canonical version string', function () {
    expect(GraphDeltaView::VERSION)->toBe('graph-delta-view-1')
        ->and(fixtureDelta()->version)->toBe('graph-delta-view-1');
});

it('requires all five sections', function () {
    $allowed = array_map(fn (Section $s) => $s->value, Section::cases());
    $present = array_keys(fixtureDelta()->sections);
    sort($allowed);
    sort($present);

    expect($present)->toBe($allowed)
        ->and($allowed)->toBe([
            'affected_context',
            'changed_directly',
            'tests_contracts',
            'unknown_impact',
            'visual_changes',
        ]);
});

it('rejects an unknown section name', function () {
    $data = json_decode(fixtureJson(), true);
    $data['sections']['invented_section'] = [];

    GraphDeltaView::fromArray($data);
})->throws(InvalidArgumentException::class, 'CONTRACT_SECTION_INVALID');

it('rejects a missing required section', function () {
    $data = json_decode(fixtureJson(), true);
    unset($data['sections']['unknown_impact']);

    GraphDeltaView::fromArray($data);
})->throws(InvalidArgumentException::class, 'CONTRACT_SECTION_MISSING');

it('distinguishes direct changes from affected context', function () {
    $delta = fixtureDelta();

    expect($delta->changedDirectly())->toHaveCount(2)
        ->and($delta->affectedContext())->toHaveCount(1);

    $direct = $delta->changedDirectly()[0];
    $affected = $delta->affectedContext()[0];

    expect($direct->id)->toBe('node-user-model')
        ->and($affected->id)->toBe('node-auth-middleware');
});

it('preserves unknown freshness without upgrading', function () {
    $delta = fixtureDelta();
    $unknown = $delta->unknownImpact()[0];

    expect($unknown->freshness)->toBe(Freshness::Unknown)
        ->and($unknown->verification)->toBe(VerificationStatus::Unresolved);
});

it('preserves unknown impact items without inventing edges', function () {
    $delta = fixtureDelta();

    expect($delta->hasUnknownImpact())->toBeTrue()
        ->and($delta->unknownImpact())->toHaveCount(1)
        ->and($delta->unknownImpact()[0]->key)->toBe('App\\Services\\NotificationService');
});

it('renders without a model call', function () {
    $delta = fixtureDelta();

    expect($delta->totalItems())->toBe(6)
        ->and($delta->toArray())->toBeArray()
        ->and($delta->toJson())->toBeString();
});

it('maps all freshness enum values', function () {
    $values = array_map(fn (Freshness $f) => $f->value, Freshness::cases());

    expect($values)->toBe(['current', 'stale', 'partially_updated', 'unavailable', 'unknown']);
});

it('maps all verification status enum values', function () {
    $values = array_map(fn (VerificationStatus $v) => $v->value, VerificationStatus::cases());

    expect($values)->toBe(['verified', 'review_required', 'blocked', 'unresolved']);
});

it('maps all change type enum values', function () {
    $values = array_map(fn (ChangeType $c) => $c->value, ChangeType::cases());

    expect($values)->toBe(['added', 'removed', 'changed']);
});

it('maps all section enum values', function () {
    $values = array_map(fn (Section $s) => $s->value, Section::cases());

    expect($values)->toBe([
        'changed_directly',
        'affected_context',
        'tests_contracts',
        'unknown_impact',
        'visual_changes',
    ]);
});

it('round-trips provenance fields', function () {
    $provenance = new DeltaProvenance(
        packHash: 'abc123',
        package: 'sifrious/molly',
        exactVersion: '0.1.0',
        repositoryRevision: 'deadbeef',
        documentationRevision: null,
    );

    $restored = DeltaProvenance::fromArray($provenance->toArray());

    expect($restored->packHash)->toBe('abc123')
        ->and($restored->package)->toBe('sifrious/molly')
        ->and($restored->exactVersion)->toBe('0.1.0')
        ->and($restored->repositoryRevision)->toBe('deadbeef')
        ->and($restored->documentationRevision)->toBeNull();
});

it('stores before and after revisions', function () {
    $delta = fixtureDelta();

    expect($delta->beforeRevision)->toBe('a1b2c3d4e5f6')
        ->and($delta->afterRevision)->toBe('f6e5d4c3b2a1');
});

it('rejects an invalid version string', function () {
    $data = json_decode(fixtureJson(), true);
    $data['version'] = 'graph-delta-view-99';

    GraphDeltaView::fromArray($data);
})->throws(InvalidArgumentException::class, 'CONTRACT_VERSION_INVALID');

it('rejects a change item with an unknown change type', function () {
    ChangeItemView::fromArray([
        'id' => 'x',
        'type' => 'model',
        'key' => 'Foo',
        'change' => 'deleted',
        'fields' => [],
        'freshness' => 'current',
        'verification' => 'verified',
    ]);
})->throws(InvalidArgumentException::class, 'change must be added, removed, or changed');

it('constructs a minimal delta view programmatically', function () {
    $item = new ChangeItemView(
        id: 'n1',
        type: 'route',
        key: '/api/users',
        change: ChangeType::Added,
        fields: ['method' => 'GET'],
        freshness: Freshness::Current,
        verification: VerificationStatus::Verified,
        sourcePath: 'routes/api.php',
    );

    $delta = new GraphDeltaView(
        version: GraphDeltaView::VERSION,
        provenance: new DeltaProvenance(packHash: 'h1', package: 'test/pkg'),
        sections: [
            'changed_directly' => [$item],
            'affected_context' => [],
            'tests_contracts' => [],
            'unknown_impact' => [],
            'visual_changes' => [],
        ],
    );

    expect($delta->totalItems())->toBe(1)
        ->and($delta->changedDirectly()[0]->key)->toBe('/api/users');
});

it('provides accessor for each section', function () {
    $delta = fixtureDelta();

    expect($delta->changedDirectly())->toBeArray()
        ->and($delta->affectedContext())->toBeArray()
        ->and($delta->testsContracts())->toBeArray()
        ->and($delta->unknownImpact())->toBeArray()
        ->and($delta->visualChanges())->toBeArray();
});

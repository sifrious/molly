<?php

use Sifrious\Molly\GraphDelta\GraphDeltaView;
use Sifrious\Molly\Livewire\ChangeImpact;

function changeImpactFixtureJson(): string
{
    return file_get_contents(__DIR__.'/../Fixtures/GraphDelta/role-feature-delta.json');
}

beforeEach(function () {
    config(['molly.ui.enabled' => true, 'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
});

it('renders the change-impact overlay from fixture JSON', function () {
    Livewire\Livewire::withoutLazyLoading()
        ->test(ChangeImpact::class, ['deltaJson' => changeImpactFixtureJson()])
        ->assertSee('App\Models\User')
        ->assertSee('Changed directly')
        ->assertSee('Unknown impact')
        ->assertSee('changed')
        ->assertSee('current');
});

it('switches between sections', function () {
    Livewire\Livewire::withoutLazyLoading()
        ->test(ChangeImpact::class, ['deltaJson' => changeImpactFixtureJson()])
        ->assertSee('App\Models\User')
        ->call('selectSection', 'affected_context')
        ->assertSee('App\Http\Middleware\CheckRole')
        ->call('selectSection', 'unknown_impact')
        ->assertSee('App\Services\NotificationService');
});

it('selects an item to show its detail', function () {
    Livewire\Livewire::withoutLazyLoading()
        ->test(ChangeImpact::class, ['deltaJson' => changeImpactFixtureJson()])
        ->call('selectItem', 'node-user-model')
        ->assertSee('app/Models/User.php');
});

it('shows before and after revisions', function () {
    Livewire\Livewire::withoutLazyLoading()
        ->test(ChangeImpact::class, ['deltaJson' => changeImpactFixtureJson()])
        ->assertSee('a1b2c3d4e5f6')
        ->assertSee('f6e5d4c3b2a1');
});

it('renders with model explanations disabled', function () {
    $delta = GraphDeltaView::fromJson(changeImpactFixtureJson());

    expect($delta->totalItems())->toBe(6)
        ->and($delta->toArray())->toBeArray();

    Livewire\Livewire::withoutLazyLoading()
        ->test(ChangeImpact::class, ['deltaJson' => changeImpactFixtureJson()])
        ->assertSuccessful();
});

it('renders the change-impact page route', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('http://localhost/molly/change-impact')
        ->assertOk()
        ->assertSee('Change impact');
});

it('distinguishes direct and affected styling cues', function () {
    Livewire\Livewire::withoutLazyLoading()
        ->test(ChangeImpact::class, ['deltaJson' => changeImpactFixtureJson()])
        ->assertSee('Changed directly')
        ->call('selectSection', 'affected_context')
        ->assertSee('Affected context does not mean broken');
});

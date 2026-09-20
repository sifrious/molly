<?php

use App\Livewire\HomeCounter;
use App\Models\User;
use Livewire\Livewire;

it('lets guests view the home counter page', function (): void {
    $this->get('/')->assertOk();
});

it('does not give guests an operational counter', function (): void {
    Livewire::test(HomeCounter::class)
        ->call('increment')
        ->assertForbidden();
});

it('lets an authenticated user increment the counter', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(HomeCounter::class)
        ->call('increment')
        ->assertSet('count', 1);
});

it('shows the login form at /login', function (): void {
    $this->get('/login')->assertOk();
});

it('logs a user out at /logout and restores the guest', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest();
});

<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Livewire;

Schema::create('users', function ($table) {
    $table->id();
    $table->timestamps();
});

Livewire::component('home-counter', new class extends Component {
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render()
    {
        return '<div>{{ $count }}</div>';
    }
});

Route::get('/', fn () => Livewire::test('home-counter'));

class HomeCounterTest extends Tests\TestCase
{
    /** @test */
    public function guests_can_see_home(): void
    {
        $this->get('/')->assertOk();
    }
}

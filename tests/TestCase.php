<?php

namespace Sifrious\Molly\Tests;

use Flux\FluxServiceProvider;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Sifrious\Molly\MollyServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, FluxServiceProvider::class, AiServiceProvider::class, McpServiceProvider::class, MollyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('molly.model', 'local-test-model');
        $app['config']->set('molly.parallel_checks', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

<?php

namespace Sifrious\Molly;

use Illuminate\Support\ServiceProvider;
use Sifrious\Molly\Console\MollyDoctorCommand;
use Sifrious\Molly\Console\MollyRunCommand;
use Sifrious\Molly\Console\MollyShowCommand;

class MollyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/molly.php', 'molly');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([MollyRunCommand::class, MollyDoctorCommand::class, MollyShowCommand::class]);
            $this->publishes([__DIR__.'/../config/molly.php' => config_path('molly.php')], 'molly-config');
        }
    }
}

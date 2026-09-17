<?php

namespace Sifrious\Molly;

use Illuminate\Support\ServiceProvider;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Complexity\Console\Commands\HotspotsCommand;
use Sifrious\Molly\Complexity\Console\Commands\LonelyFilesCommand;
use Sifrious\Molly\Complexity\Console\Commands\OwnedDiffCommand;
use Sifrious\Molly\Complexity\Console\Commands\ScanCommand;
use Sifrious\Molly\Complexity\Console\Commands\WeldsCommand;
use Sifrious\Molly\Console\MollyCreateCommand;
use Sifrious\Molly\Console\MollyDoctorCommand;
use Sifrious\Molly\Console\MollyImportCommand;
use Sifrious\Molly\Console\MollyRetryCommand;
use Sifrious\Molly\Console\MollyRunCommand;
use Sifrious\Molly\Console\MollyShowCommand;
use Sifrious\Molly\Console\MollyStartCommand;
use Sifrious\Molly\Console\MollyStopCommand;
use Sifrious\Molly\Console\MollyTaskCommand;
use Sifrious\Molly\Console\MollyTasksCommand;

class MollyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/molly.php', 'molly');
        $this->mergeConfigFrom(__DIR__.'/../config/molly-complexity.php', 'molly-complexity');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([MollyRunCommand::class, MollyDoctorCommand::class, MollyShowCommand::class,
                MollyCreateCommand::class, MollyTasksCommand::class, MollyTaskCommand::class,
                MollyStartCommand::class, MollyRetryCommand::class, MollyStopCommand::class, MollyImportCommand::class]);
            if ($this->app->make(Clever::class)->enabled()) {
                $this->commands([ScanCommand::class, OwnedDiffCommand::class, WeldsCommand::class, LonelyFilesCommand::class, HotspotsCommand::class]);
            }
            $this->publishes([
                __DIR__.'/../config/molly.php' => config_path('molly.php'),
                __DIR__.'/../config/molly-complexity.php' => config_path('molly-complexity.php'),
            ], 'molly-config');
        }
    }
}

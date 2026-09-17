<?php

namespace Sifrious\Molly;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Livewire\Livewire;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Complexity\Console\Commands\HotspotsCommand;
use Sifrious\Molly\Complexity\Console\Commands\LonelyFilesCommand;
use Sifrious\Molly\Complexity\Console\Commands\OwnedDiffCommand;
use Sifrious\Molly\Complexity\Console\Commands\ScanCommand;
use Sifrious\Molly\Complexity\Console\Commands\WeldsCommand;
use Sifrious\Molly\Console\MollyAdviceCommand;
use Sifrious\Molly\Console\MollyChatCommand;
use Sifrious\Molly\Console\MollyCheckCommand;
use Sifrious\Molly\Console\MollyConnectionsCommand;
use Sifrious\Molly\Console\MollyCreateCommand;
use Sifrious\Molly\Console\MollyDoctorCommand;
use Sifrious\Molly\Console\MollyImportCommand;
use Sifrious\Molly\Console\MollyJournalCommand;
use Sifrious\Molly\Console\MollyLinkThreadCommand;
use Sifrious\Molly\Console\MollyNameCommand;
use Sifrious\Molly\Console\MollyPlanCommand;
use Sifrious\Molly\Console\MollyRetryCommand;
use Sifrious\Molly\Console\MollyReviewCommitCommand;
use Sifrious\Molly\Console\MollyRunCommand;
use Sifrious\Molly\Console\MollySetupCommand;
use Sifrious\Molly\Console\MollyShowCommand;
use Sifrious\Molly\Console\MollyStartCommand;
use Sifrious\Molly\Console\MollyStopCommand;
use Sifrious\Molly\Console\MollyTaskCommand;
use Sifrious\Molly\Console\MollyTasksCommand;
use Sifrious\Molly\Livewire\RunStatus;
use Sifrious\Molly\Mcp\MollyServer;

class MollyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/molly.php', 'molly');
        $this->mergeConfigFrom(__DIR__.'/../config/molly-complexity.php', 'molly-complexity');
    }

    public function boot(): void
    {
        if ($this->app->environment('local', 'testing')) {
            Mcp::local('molly', MollyServer::class);
        }
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'molly');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->bound('livewire.finder')) {
            Livewire::component('molly-run-status', RunStatus::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MollyCheckCommand::class, MollyRunCommand::class, MollyDoctorCommand::class, MollyShowCommand::class,
                MollyCreateCommand::class, MollyTasksCommand::class, MollyTaskCommand::class,
                MollyStartCommand::class, MollyRetryCommand::class, MollyStopCommand::class, MollyImportCommand::class, MollyNameCommand::class,
                MollyJournalCommand::class, MollyConnectionsCommand::class, MollyLinkThreadCommand::class, MollyAdviceCommand::class,
                MollyPlanCommand::class, MollyReviewCommitCommand::class, MollySetupCommand::class, MollyChatCommand::class]);
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

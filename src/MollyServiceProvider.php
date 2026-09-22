<?php

namespace Sifrious\Molly;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Livewire\Livewire;
use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Sifrious\Molly\Actions\DetectFalseGreen;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Complexity\Console\Commands\HotspotsCommand;
use Sifrious\Molly\Complexity\Console\Commands\LonelyFilesCommand;
use Sifrious\Molly\Complexity\Console\Commands\OwnedDiffCommand;
use Sifrious\Molly\Complexity\Console\Commands\ScanCommand;
use Sifrious\Molly\Complexity\Console\Commands\WeldsCommand;
use Sifrious\Molly\Console\MollyAdviceCommand;
use Sifrious\Molly\Console\MollyApproveCommand;
use Sifrious\Molly\Console\MollyBloomContractCommand;
use Sifrious\Molly\Console\MollyChatCommand;
use Sifrious\Molly\Console\MollyCheckCommand;
use Sifrious\Molly\Console\MollyCommentCommand;
use Sifrious\Molly\Console\MollyConnectionsCommand;
use Sifrious\Molly\Console\MollyCreateCommand;
use Sifrious\Molly\Console\MollyDecideCommand;
use Sifrious\Molly\Console\MollyDemoCommand;
use Sifrious\Molly\Console\MollyDoctorCommand;
use Sifrious\Molly\Console\MollyGraphsBootstrapCommand;
use Sifrious\Molly\Console\MollyGraphsRetryCommand;
use Sifrious\Molly\Console\MollyHandoffCommand;
use Sifrious\Molly\Console\MollyImportCommand;
use Sifrious\Molly\Console\MollyInspectCommand;
use Sifrious\Molly\Console\MollyJournalCommand;
use Sifrious\Molly\Console\MollyKnowledgeIndexCommand;
use Sifrious\Molly\Console\MollyKnowledgePackCommand;
use Sifrious\Molly\Console\MollyKnowledgeQueryCommand;
use Sifrious\Molly\Console\MollyLinkThreadCommand;
use Sifrious\Molly\Console\MollyLockTestCommand;
use Sifrious\Molly\Console\MollyMergedCommand;
use Sifrious\Molly\Console\MollyNameCommand;
use Sifrious\Molly\Console\MollyPlanCommand;
use Sifrious\Molly\Console\MollyPrBodyCommand;
use Sifrious\Molly\Console\MollyProjectIndexCommand;
use Sifrious\Molly\Console\MollyProjectInitCommand;
use Sifrious\Molly\Console\MollyProjectNewCommand;
use Sifrious\Molly\Console\MollyProjectQueryCommand;
use Sifrious\Molly\Console\MollyProjectsCommand;
use Sifrious\Molly\Console\MollyPrOpenedCommand;
use Sifrious\Molly\Console\MollyReceiptCommand;
use Sifrious\Molly\Console\MollyRetryCommand;
use Sifrious\Molly\Console\MollyReviewCommitCommand;
use Sifrious\Molly\Console\MollyRunCommand;
use Sifrious\Molly\Console\MollySettingsCommand;
use Sifrious\Molly\Console\MollySettingsSetCommand;
use Sifrious\Molly\Console\MollySetupCommand;
use Sifrious\Molly\Console\MollyShowCommand;
use Sifrious\Molly\Console\MollyStartCommand;
use Sifrious\Molly\Console\MollyStopCommand;
use Sifrious\Molly\Console\MollyTaskCommand;
use Sifrious\Molly\Console\MollyTasksCommand;
use Sifrious\Molly\Knowledge\LaravelContainerGraph;
use Sifrious\Molly\Knowledge\LaravelEloquentGraph;
use Sifrious\Molly\Knowledge\LaravelEventsGraph;
use Sifrious\Molly\Knowledge\LaravelQueueGraph;
use Sifrious\Molly\Knowledge\LaravelRoutingGraph;
use Sifrious\Molly\Knowledge\LaravelTestingGraph;
use Sifrious\Molly\Knowledge\LaravelValidationGraph;
use Sifrious\Molly\Livewire\RunStatus;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Verification\FalseGreenVerifier;

class MollyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FalseGreenVerifier::class, DetectFalseGreen::class);
        $this->app->when(BootstrapProjectKnowledgeGraphs::class)
            ->needs('$laravelGraphs')
            ->give(function ($app) {
                return [
                    $app->make(LaravelQueueGraph::class),
                    $app->make(LaravelRoutingGraph::class),
                    $app->make(LaravelTestingGraph::class),
                    $app->make(LaravelValidationGraph::class),
                    $app->make(LaravelContainerGraph::class),
                    $app->make(LaravelEloquentGraph::class),
                    $app->make(LaravelEventsGraph::class),
                ];
            });
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
            $this->commands([MollyCheckCommand::class, MollyRunCommand::class, MollyDoctorCommand::class, MollyShowCommand::class, MollyReceiptCommand::class,
                MollyBloomContractCommand::class, MollyApproveCommand::class, MollyLockTestCommand::class, MollyCreateCommand::class, MollyDemoCommand::class, MollyTasksCommand::class, MollyTaskCommand::class,
                MollyStartCommand::class, MollyRetryCommand::class, MollyStopCommand::class, MollyImportCommand::class, MollyCommentCommand::class,
                MollyPrBodyCommand::class, MollyPrOpenedCommand::class, MollyMergedCommand::class, MollyHandoffCommand::class, MollyNameCommand::class,
                MollyJournalCommand::class, MollyDecideCommand::class, MollyConnectionsCommand::class, MollyLinkThreadCommand::class, MollyAdviceCommand::class,
                MollyPlanCommand::class, MollyReviewCommitCommand::class, MollySetupCommand::class, MollyChatCommand::class,
                MollyProjectNewCommand::class, MollyProjectInitCommand::class, MollyProjectsCommand::class, MollyGraphsBootstrapCommand::class, MollyGraphsRetryCommand::class, MollyInspectCommand::class, MollySettingsSetCommand::class, MollySettingsCommand::class,
                MollyKnowledgeIndexCommand::class, MollyKnowledgeQueryCommand::class, MollyKnowledgePackCommand::class, MollyProjectIndexCommand::class, MollyProjectQueryCommand::class]);
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

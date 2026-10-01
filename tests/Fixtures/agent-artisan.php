<?php

/*
 * Artisan for a Laravel 13 app with laravel/pao, run by an AI agent: Laravel's OutputStyle
 * is replaced by AgentOutputStyle, which rewrites output the way pao does. The test sets
 * CLAUDECODE and AI_AGENT in the environment, as an agent's shell would.
 */

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Sifrious\Molly\MollyServiceProvider;
use Sifrious\Molly\Tests\Support\AgentOutputStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->register(AiServiceProvider::class);
$app->register(MollyServiceProvider::class);
$app->bind(OutputStyle::class, AgentOutputStyle::class);

exit($kernel->handle(new ArgvInput, new ConsoleOutput));

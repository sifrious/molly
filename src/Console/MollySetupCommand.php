<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Sifrious\Molly\Actions\ConfigureAgent;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

use function Laravel\Prompts\select;

class MollySetupCommand extends Command
{
    protected $signature = 'molly:setup {--agent= : amp or ollama} {--model= : Installed local Ollama model} {--no-login : Configure Amp without opening its login flow} {--json : Print setup results without interactive prompts}';

    protected $description = 'Choose an agent and connect Amp to Molly or select a local Ollama model';

    public function handle(ConfigureAgent $configure, ExecutableFinder $finder): int
    {
        try {
            $interactive = $this->input->isInteractive() && ! $this->option('json');
            $agent = $this->option('agent');
            if ($agent === null && $interactive) {
                $agent = select('Which agent would you like to use?', ['amp' => 'Amp account', 'ollama' => 'Local Ollama']);
            }
            if (! in_array($agent, ['amp', 'ollama'], true)) {
                throw new RuntimeException('Provide --agent=amp or --agent=ollama.');
            }
            $model = $this->option('model');
            $next = [];
            if ($agent === 'amp') {
                if ($model !== null) {
                    throw new RuntimeException('--model selects a local Ollama model. Omit it for Amp.');
                }
                $amp = $finder->find('amp');
                if ($amp === null) {
                    throw new RuntimeException('Amp is not on PATH. Install the official CLI from https://ampcode.com, then run setup again.');
                }
                $login = [$amp, 'login'];
                if (! $this->option('no-login') && $interactive && defined('STDIN') && stream_isatty(STDIN) && SymfonyProcess::isTtySupported()) {
                    if (! Process::path(base_path())->forever()->tty()->run($login)->successful()) {
                        throw new RuntimeException('Amp login did not finish. Run amp login in your terminal and try setup again.');
                    }
                } else {
                    $next[] = (new SymfonyProcess($login))->getCommandLine();
                }
                $command = [$amp, 'mcp', 'add', 'molly', '--workspace', '--', PHP_BINARY, base_path('artisan'), 'mcp:start', 'molly'];
                if (! Process::path(base_path())->timeout(30)->run($command)->successful()) {
                    throw new RuntimeException('Amp could not save the workspace MCP connection. Run amp mcp add --help for configuration options.');
                }
                $next[] = 'amp mcp approve molly';
                $next[] = 'amp mcp doctor molly';
            } elseif ($model === null && $interactive) {
                $models = $configure->models();
                if ($models === []) {
                    throw new RuntimeException('No local Ollama models are installed. Pull a model with Ollama, then run setup again.');
                }
                $model = select('Which installed model should Molly use?', $models);
            }
            $configure->handle($agent, $model);
            if (app()->configurationIsCached()) {
                $next[] = 'php artisan config:clear';
            }
            $result = ['status' => 'configured', 'agent' => $agent, 'model' => $model, 'next_commands' => $next];
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } else {
                $this->info('Saved the '.$agent.' configuration.');
                foreach ($next as $command) {
                    $this->line($command);
                }
                if ($agent === 'amp') {
                    $this->line('Amp manages your login. Use molly:chat to open its terminal interface with Molly connected.');
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'failed', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}

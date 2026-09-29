<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;

class MollyChatCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:chat {--json : Print the Amp launch arguments without starting a session}';

    protected $description = 'Open Amp with a Molly MCP connection for this session';

    public function handle(ExecutableFinder $finder): int
    {
        $amp = $finder->find('amp');
        if ($amp === null) {
            $message = 'AMP_MISSING: Amp is not on PATH. Install it from https://ampcode.com, then run molly:setup --agent=amp.';

            return $this->reportFailure($message, ['status' => 'failed', 'error' => $message]);
        }
        $config = ['molly' => ['command' => PHP_BINARY, 'args' => [base_path('artisan'), 'mcp:start', 'molly']]];
        $command = [$amp, '--mcp-config', json_encode($config, JSON_THROW_ON_ERROR)];
        if ($this->option('json')) {
            $this->writeJson(['command' => $command, 'workspace' => base_path()]);

            return self::SUCCESS;
        }
        if (! $this->input->isInteractive() || ! defined('STDIN') || ! stream_isatty(STDIN) || ! SymfonyProcess::isTtySupported()) {
            $this->line((new SymfonyProcess($command))->getCommandLine());

            return $this->reportFailure('TERMINAL_REQUIRED: Open Amp in an interactive terminal with the command above.');
        }

        return Process::path(base_path())->forever()->tty()->run($command)->exitCode() ?? self::FAILURE;
    }
}

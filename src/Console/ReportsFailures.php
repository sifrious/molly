<?php

namespace Sifrious\Molly\Console;

use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\error;

/**
 * Keep diagnostics on stderr. A failed Molly command prints its message on stderr; with
 * --json it still prints its JSON document on stdout and adds one `CODE: message` line
 * on stderr. When the command runs through Artisan::call there is no stderr, so the
 * message stays in the command output as before.
 */
trait ReportsFailures
{
    /**
     * Invalid arguments and options fail the same way instead of reaching Laravel's
     * exception renderer, which writes them to stdout.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $exception) {
            if (! $output instanceof ConsoleOutputInterface) {
                throw $exception;
            }

            $message = 'ARGUMENTS_INVALID: '.$exception->getMessage().' Run php artisan '.$this->getName().' --help for usage.';
            if ($input->hasParameterOption('--json', true) && $this->getDefinition()->hasOption('json')) {
                $output->writeln(json_encode(['status' => 'error', 'error' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            }
            $output->getErrorOutput()->writeln(self::diagnosticLine($message), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
    }

    /** @param  array<string, mixed>|null  $document  printed on stdout when the caller asked for --json */
    protected function reportFailure(string $message, ?array $document = null): int
    {
        $json = $this->hasOption('json') && $this->option('json');
        $console = $this->output->getOutput();
        $stderr = $console instanceof ConsoleOutputInterface ? $console->getErrorOutput() : null;

        if ($json) {
            $this->line(json_encode($document ?? ['status' => 'error', 'error' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            $stderr?->writeln(self::diagnosticLine($message), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        if ($stderr === null) {
            error($message);

            return self::FAILURE;
        }

        Prompt::setOutput($stderr);
        try {
            error($message);
        } finally {
            Prompt::setOutput($this->output);
        }

        return self::FAILURE;
    }

    private static function diagnosticLine(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $message));

        return preg_match('/\A[A-Z][A-Z0-9_]+: /', $line) === 1 ? $line : 'COMMAND_FAILED: '.$line;
    }
}

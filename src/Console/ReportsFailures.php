<?php

namespace Sifrious\Molly\Console;

use Closure;
use Laravel\Prompts\Prompt;
use Sifrious\Molly\AuthoredTestBroken;
use Sifrious\Molly\ChoiceRequired;
use Sifrious\Molly\Redaction\SecretRedactor;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\warning;

/**
 * Keep diagnostics on stderr. A failed Molly command prints its message on stderr; with
 * --json it still prints its JSON document on stdout and adds one `CODE: message` line
 * on stderr. When the command runs through Artisan::call there is no stderr, so the
 * message stays in the command output as before. JSON documents go through writeJson().
 */
trait ReportsFailures
{
    use WritesJson;

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
                $this->writeJson(['status' => 'error', 'error' => $message]);
            }
            $output->getErrorOutput()->writeln(self::diagnosticLine($message), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
    }

    /** @param  array<string, mixed>|null  $document  printed on stdout when the caller asked for --json */
    protected function reportFailure(string $message, ?array $document = null): int
    {
        $redactor = app(SecretRedactor::class);
        $message = $redactor->text($message);
        $document = $document === null ? null : $redactor->value($document);
        $json = $this->hasOption('json') && $this->option('json');
        $console = $this->output->getOutput();
        $stderr = $console instanceof ConsoleOutputInterface ? $console->getErrorOutput() : null;

        if ($json) {
            $this->writeJson($document ?? ['status' => 'error', 'error' => $message]);
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

    /**
     * Run the action. When it asks for a choice on a terminal without --json,
     * offer the choices with Laravel Prompts, fill the argument or option, and
     * run it again.
     */
    protected function offeringChoices(Closure $action): mixed
    {
        for ($asked = 0; ; $asked++) {
            try {
                return $action();
            } catch (ChoiceRequired $exception) {
                $definition = $this->getNativeDefinition();
                $fillable = $definition->hasOption($exception->input) || $definition->hasArgument($exception->input);
                $json = $this->hasOption('json') && $this->option('json');
                if ($json || ! $this->input->isInteractive() || ! $fillable || $exception->choices === [] || $asked >= 3) {
                    throw $exception;
                }

                warning(strtok($exception->getMessage(), '.').'.');
                $label = 'Choose '.($exception->multiple ? 'one or more values' : 'a value').' for '.$exception->input;
                $answer = $exception->multiple
                    ? multiselect($label, $exception->choices, required: true, scroll: 10)
                    : search($label, fn (string $value): array => array_filter($exception->choices, fn (string $choice): bool => $value === '' || str_contains(strtolower($choice), strtolower($value))), scroll: 10);
                $definition->hasOption($exception->input)
                    ? $this->input->setOption($exception->input, $answer)
                    : $this->input->setArgument($exception->input, $answer);
            }
        }
    }

    /**
     * Report a failure. An error that asks for a choice adds the choices and a
     * command to run again, to the message and to the JSON document.
     *
     * @param  array<string, mixed>  $document
     */
    protected function reportException(Throwable $exception, array $document = ['status' => 'error']): int
    {
        [$message, $choices] = $this->failureDetails($exception);

        return $this->reportFailure($message, [...$document, 'error' => $message, ...$choices]);
    }

    /**
     * The failure message and, for an error that asks for a choice, the
     * choices and the command to run again. For an authored test that cannot
     * run, the check and the next step.
     *
     * @return array{0: string, 1: array{choices?: list<array{value: string, label: string}>, rerun?: string, authored_test?: array<string, mixed>, next?: array{command: string, reason: string}}}
     */
    protected function failureDetails(Throwable $exception): array
    {
        if ($exception instanceof AuthoredTestBroken) {
            return [$exception->getMessage(), ['authored_test' => $exception->check, 'next' => $exception->next]];
        }
        if (! $exception instanceof ChoiceRequired) {
            return [$exception->getMessage(), []];
        }
        $first = array_key_first($exception->choices);
        $rerun = $exception->rerun ?? $this->commandLine($first === null ? [] : [$exception->input => (string) $first]);

        return [$exception->getMessage().' Run: '.$rerun, ['choices' => $exception->choiceList(), 'rerun' => $rerun]];
    }

    /**
     * This command as typed, with each argument or option named in $replace set to
     * that value instead. A null or false value leaves it out.
     *
     * @param  array<string, string|bool|null>  $replace
     */
    protected function commandLine(array $replace = []): string
    {
        $words = ['php', 'artisan', (string) $this->getName()];
        $quote = fn (string $value): string => preg_match('~\A[A-Za-z0-9_/.:=@%+,-]+\z~', $value) === 1 ? $value : escapeshellarg($value);
        $definition = $this->getNativeDefinition();
        foreach ($definition->getArguments() as $name => $argument) {
            $value = array_key_exists($name, $replace) ? $replace[$name] : $this->input->getArgument($name);
            foreach ((array) $value as $item) {
                if (is_string($item) && $item !== '') {
                    $words[] = $quote($item);
                }
            }
        }
        foreach ($definition->getOptions() as $name => $option) {
            $value = array_key_exists($name, $replace) ? $replace[$name] : $this->input->getOption($name);
            if ($value === true) {
                $words[] = '--'.$name;
            }
            foreach (is_bool($value) || $value === null ? [] : (array) $value as $item) {
                $words[] = '--'.$name.'='.$quote((string) $item);
            }
        }

        return implode(' ', $words);
    }

    private static function diagnosticLine(string $message): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $message));

        return preg_match('/\A[A-Z][A-Z0-9_]+: /', $line) === 1 ? $line : 'COMMAND_FAILED: '.$line;
    }
}

<?php

namespace Sifrious\Molly\Tests\Support;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stands in for laravel/pao 1.1.5, a dev dependency of new Laravel 13 apps. When Artisan runs
 * under an AI agent (CLAUDECODE, AI_AGENT, CODEX_*, and similar), pao binds its PaoOutputStyle
 * in place of Laravel's OutputStyle. That class formats each message, then cleans it with
 * OutputCleaner, even when the caller asks for OUTPUT_RAW. This copies both steps from
 * src/Laravel/PaoOutputStyle.php and src/OutputCleaner.php, so the tests need neither pao
 * nor an agent to see what an agent session prints.
 */
class AgentOutputStyle extends OutputStyle
{
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $output->setDecorated(false);

        parent::__construct($input, $output);
    }

    public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
    {
        parent::write($this->clean($messages, ($options & self::OUTPUT_RAW) === 0), $newline, $options);
    }

    public function writeln(string|iterable $messages, int $type = self::OUTPUT_NORMAL): void
    {
        parent::writeln($this->clean($messages, ($type & self::OUTPUT_RAW) === 0), $type);
    }

    public static function cleaned(string $output): string
    {
        $output = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
        $output = (string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $output);
        $output = (string) preg_replace('/\x{FFFD}/u', '', $output);
        $output = (string) preg_replace('/[─━│┌┐└┘├┤┬┴┼▓░▒═║╔╗╚╝╠╣╦╩╬➜▶►⚠✖✔●◆■▪→←↑↓▕⨯✕]+/u', '', $output);
        $output = (string) preg_replace('/\.{3,}/', '..', $output);
        $output = (string) preg_replace('/[ \t]+/', ' ', $output);

        return (string) preg_replace('/\n\s*\n/', "\n", $output);
    }

    /** @return string|list<string> */
    private function clean(string|iterable $messages, bool $format): string|array
    {
        $strip = fn (string $message): string => self::cleaned($format ? (string) (new OutputFormatter(false))->format($message) : $message);

        return is_string($messages) ? $strip($messages) : array_values(array_map($strip, [...$messages]));
    }
}

<?php

namespace Sifrious\Molly\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print a JSON document on stdout, byte for byte as PHP encodes it.
 *
 * The document goes to the Symfony console output under Laravel's OutputStyle, as raw text.
 * OutputStyle would read `<info>` or `<fg=red>` inside a value as a style tag. In an AI agent
 * session, laravel/pao, a dev dependency of new Laravel 13 apps, binds its own OutputStyle,
 * which collapses runs of spaces and shortens `...` to `..` even in raw writes. Neither one
 * sees this path, so a script, Bloom, or `molly:preflight --snapshot` reads the same bytes
 * in every terminal. Human output keeps using Laravel Prompts.
 */
trait WritesJson
{
    protected function writeJson(mixed $value, int $flags = 0): void
    {
        $this->output->getOutput()->writeln(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | $flags),
            OutputInterface::OUTPUT_RAW,
        );
    }
}

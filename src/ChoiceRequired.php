<?php

namespace Sifrious\Molly;

use RuntimeException;

/**
 * An error that asks the caller to choose a value. The message lists the
 * choices so MCP and log readers see them; console commands also offer them
 * with Laravel Prompts on a terminal, or print them as JSON with a command
 * to run again.
 */
class ChoiceRequired extends RuntimeException
{
    /**
     * @param  array<string, string>  $choices  value => label
     * @param  string  $input  the command argument or option the choice fills, such as file or test
     */
    public function __construct(
        string $message,
        public readonly array $choices,
        public readonly string $input,
        public readonly bool $multiple = false,
        public readonly ?string $rerun = null,
    ) {
        $labels = array_values($choices);
        $listed = array_slice($labels, 0, 20);
        $more = count($labels) - count($listed);

        parent::__construct($message.($labels === []
            ? ' Molly found nothing to offer.'
            : ' Choices: '.implode(', ', $listed).($more > 0 ? ', and '.$more.' more' : '').'.'));
    }

    /** @param  list<string>  $values */
    public static function fromList(string $message, array $values, string $input, bool $multiple = false, ?string $rerun = null): self
    {
        $values = array_values(array_unique($values));

        return new self($message, array_combine($values, $values), $input, $multiple, $rerun);
    }

    /** @return list<array{value: string, label: string}> */
    public function choiceList(): array
    {
        return array_map(fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label], array_keys($this->choices), $this->choices);
    }
}

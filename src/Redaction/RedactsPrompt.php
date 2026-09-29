<?php

namespace Sifrious\Molly\Redaction;

/**
 * A saved prompt stays as typed, because the model needs it. Every place that shows the
 * record, such as command output, MCP, and the web pages, reads redactedPrompt(), and
 * toArray() returns the redacted prompt.
 */
trait RedactsPrompt
{
    public function redactedPrompt(): string
    {
        return app(SecretRedactor::class)->text((string) $this->prompt, is_string($this->workspace) ? $this->workspace : null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $array = parent::toArray();
        if (array_key_exists('prompt', $array)) {
            $array['prompt'] = $this->redactedPrompt();
        }

        return $array;
    }
}

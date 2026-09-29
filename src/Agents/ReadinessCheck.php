<?php

namespace Sifrious\Molly\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The bounded inference step of the model readiness check: one arithmetic question
 * with a known answer, capped at MAX_TOKENS generated tokens and molly.timeout seconds.
 */
#[MaxTokens(self::MAX_TOKENS)]
class ReadinessCheck implements Agent, HasStructuredOutput
{
    use Promptable;

    public const MAX_TOKENS = 1024;

    public function instructions(): string
    {
        return 'Answer the arithmetic question in the input. Return JSON only, with the integer result in answer.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->integer()->required()];
    }
}

<?php

namespace Sifrious\Molly\Seams;

use Sifrious\Molly\Actions\CheckSeamHarness;
use Sifrious\Molly\Actions\CheckSeamSensitivity;
use Sifrious\Molly\Actions\CheckSeamState;
use Sifrious\Molly\Actions\VerifySeamBehavior;

final class SeamSteps
{
    public const REQUIRED = ['harness', 'pre', 'lock', 'implementation', 'post', 'sensitivity', 'cleanup', 'handoff'];

    public function __construct(private ValidateSchema $schemas) {}

    public function validate(array $steps): void
    {
        $core = array_values(array_filter(array_column($steps, 'id'), fn (string $id): bool => in_array($id, self::REQUIRED, true)));
        if ($core !== self::REQUIRED) {
            throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Instruction edits cannot omit or reorder required verification steps.', 'plan.json', self::REQUIRED, $core);
        }
        foreach ($steps as $step) {
            if (in_array($step['id'], self::REQUIRED, true) && $step['handler'] !== 'seam.'.$step['id']) {
                throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Required steps must use their registered verification handlers.', 'plan.json');
            }
            if (! in_array($step['id'], self::REQUIRED, true) && str_starts_with($step['handler'], 'seam.')) {
                throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Additional steps must use a registered extension handler.', 'plan.json');
            }
            $errors = $this->schemas->handle($step['arguments'], $this->handler($step['handler'])->argumentSchema(), 'plan.json');
            if ($errors !== []) {
                throw new SeamError('INPUT_INVALID', 'Step arguments do not match the registered handler schema.', 'plan.json', null, $errors);
            }
        }
    }

    public function handler(string $name): StepHandler
    {
        $class = match ($name) {
            'seam.harness' => CheckSeamHarness::class,
            'seam.pre', 'seam.post' => VerifySeamBehavior::class,
            'seam.sensitivity' => CheckSeamSensitivity::class,
            'seam.lock', 'seam.implementation', 'seam.cleanup', 'seam.handoff' => CheckSeamState::class,
            default => config('molly-seams.handlers', [])[$name] ?? null,
        };
        if (! is_string($class) || ! is_a($class, StepHandler::class, true)) {
            throw new SeamError('HANDLER_UNAVAILABLE', 'Register this typed handler in the application before selecting it.', 'plan.json', StepHandler::class, $name);
        }

        return app($class);
    }

    public static function emptyArguments(): array
    {
        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object', 'additionalProperties' => false];
    }
}

<?php

namespace Sifrious\Molly\Classification;

use Composer\InstalledVersions;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class DetectLaravelAiClassification
{
    /** Future/current FQCNs probed as strings so missing classes never fatal on autoload. */
    private const CLASSIFICATION = 'Laravel\\Ai\\Classification';

    private const CHOICE = 'Laravel\\Ai\\Classification\\Choice';

    private const CHOICE_ANSWER = 'Laravel\\Ai\\Responses\\Data\\ChoiceAnswer';

    private const LAB = 'Laravel\\Ai\\Enums\\Lab';

    public function adapter(): string
    {
        // Boolean decide adapter activates only when the macro is registered.
        if ($this->supportsDecide()) {
            return 'laravel-ai.decide';
        }

        if ($this->supportsChoice()) {
            return 'laravel-ai.choice';
        }

        return 'molly.fallback';
    }

    /**
     * Composer pretty version for journal provenance only.
     * Returns null when metadata is missing. Never used as a capability check —
     * adapter selection stays feature-based (decide macro / choice probes).
     */
    public function version(): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            if (! InstalledVersions::isInstalled('laravel/ai')) {
                return null;
            }

            $pretty = InstalledVersions::getPrettyVersion('laravel/ai');
        } catch (\Throwable) {
            return null;
        }

        return is_string($pretty) && $pretty !== '' ? $pretty : null;
    }

    /**
     * Independent boolean capability: Str::hasMacro('decide') only.
     * Never infer from package version, package presence, or structured-agent support.
     */
    public function supportsDecide(): bool
    {
        if (! class_exists(Str::class)) {
            return false;
        }

        return Str::hasMacro('decide');
    }

    /**
     * Choice capability: Classification + Choice + ChoiceAnswer + Lab::TypeSafe together.
     * Package presence or structured-agent support alone is not enough.
     */
    public function supportsChoice(): bool
    {
        if (! $this->classAvailable(self::CLASSIFICATION)
            || ! $this->classAvailable(self::CHOICE)
            || ! $this->classAvailable(self::CHOICE_ANSWER)
            || ! $this->labHasTypeSafe()) {
            return false;
        }

        return true;
    }

    public function supportsStructuredAgents(): bool
    {
        return interface_exists(HasStructuredOutput::class)
            && class_exists(StructuredAgentResponse::class);
    }

    private function classAvailable(string $class): bool
    {
        try {
            return class_exists($class) || interface_exists($class);
        } catch (\Throwable) {
            return false;
        }
    }

    private function labHasTypeSafe(): bool
    {
        if (! enum_exists(self::LAB)) {
            return false;
        }

        try {
            /** @var class-string<\UnitEnum> $lab */
            $lab = self::LAB;
            foreach ($lab::cases() as $case) {
                if ($case->name === 'TypeSafe' || (is_string($case->value ?? null) && strcasecmp((string) $case->value, 'typesafe') === 0)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}

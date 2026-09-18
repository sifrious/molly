<?php

namespace Sifrious\Molly\Classification;

use Composer\InstalledVersions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class DetectLaravelAiClassification
{
    public function adapter(): string
    {
        if ($this->supportsStructuredAgents()) {
            return 'laravel-ai.structured';
        }

        return 'molly.fallback';
    }

    public function version(): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled('laravel/ai')) {
            return null;
        }

        return InstalledVersions::getPrettyVersion('laravel/ai');
    }

    public function supportsStructuredAgents(): bool
    {
        return interface_exists(HasStructuredOutput::class)
            && class_exists(StructuredAgentResponse::class);
    }
}

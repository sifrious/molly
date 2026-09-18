<?php

namespace Sifrious\Molly\Classification;

final class ResolveClassificationAdapter
{
    public function __construct(
        private DetectLaravelAiClassification $detect,
        private LaravelAiClassificationAdapter $laravelAi,
        private FallbackClassificationAdapter $fallback,
    ) {}

    public function handle(): ClassificationAdapter
    {
        return $this->detect->supportsStructuredAgents() ? $this->laravelAi : $this->fallback;
    }
}

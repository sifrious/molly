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
        if (config('molly.jev.enabled', false) !== true) {
            return $this->fallback;
        }

        return $this->detect->supportsDecide() ? $this->laravelAi : $this->fallback;
    }
}

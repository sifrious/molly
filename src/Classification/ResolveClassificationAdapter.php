<?php

namespace Sifrious\Molly\Classification;

final class ResolveClassificationAdapter
{
    public function __construct(
        private JevGate $gate,
        private DetectLaravelAiClassification $detect,
        private LaravelAiClassificationAdapter $laravelAi,
        private FallbackClassificationAdapter $fallback,
    ) {}

    public function handle(): ClassificationAdapter
    {
        if (! $this->gate->enabled()) {
            return $this->fallback;
        }

        return $this->detect->supportsDecide() ? $this->laravelAi : $this->fallback;
    }
}

<?php

namespace Sifrious\Molly\Seams;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;

interface StepHandler
{
    public function argumentSchema(): array;

    public function handle(SeamRevision $revision, Run $attempt, array $arguments, string $evidenceDirectory): StepResult;
}

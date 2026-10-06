<?php

namespace Sifrious\Molly\Seams;

interface TestAdapter
{
    public function renderCase(array $case, bool $baseline = false): string;
}

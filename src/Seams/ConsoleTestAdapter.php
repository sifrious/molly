<?php

namespace Sifrious\Molly\Seams;

final class ConsoleTestAdapter implements TestAdapter
{
    public function renderCase(array $case, bool $baseline = false): string
    {
        $name = var_export('seam '.$case['id'].': '.$case['description'], true);
        $command = var_export($case['command'], true);
        $arguments = var_export($case['arguments'], true);
        $expected = $case[$baseline ? 'before' : 'expected'];
        $output = var_export($expected['output'], true);
        $exit = var_export($expected['exit'], true);

        return "it({$name}, function () {\n    \$this->artisan({$command}, {$arguments})->expectsOutput({$output})->assertExitCode({$exit});\n});";
    }
}

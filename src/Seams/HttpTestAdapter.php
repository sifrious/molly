<?php

namespace Sifrious\Molly\Seams;

final class HttpTestAdapter implements TestAdapter
{
    public function renderCase(array $case, bool $baseline = false): string
    {
        $name = var_export('seam '.$case['id'].': '.$case['description'], true);
        $method = var_export($case['method'], true);
        $uri = var_export($case['uri'], true);
        $input = var_export($case['input'], true);
        $expected = $case[$baseline ? 'before' : 'expected'];
        $status = var_export($expected['status'], true);
        $json = array_key_exists('json', $expected) ? "\n    \$response->assertExactJson(".var_export($expected['json'], true).');' : '';

        return "it({$name}, function () {\n    \$response = \$this->json({$method}, {$uri}, {$input});\n    \$response->assertStatus({$status});{$json}\n});";
    }
}

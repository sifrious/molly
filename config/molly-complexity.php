<?php

use Sifrious\Molly\Complexity\Probes\HotspotsProbe;
use Sifrious\Molly\Complexity\Probes\LonelyFilesProbe;
use Sifrious\Molly\Complexity\Probes\OwnedDiffProbe;
use Sifrious\Molly\Complexity\Probes\WeldedCallSitesProbe;

return [
    // Null enables measurements in local and testing. Production always disables them.
    'enabled' => env('MOLLY_COMPLEXITY_ENABLED'),
    // Null measures the host application. Task runs override the root and report path.
    'root' => null,
    'report' => ['path' => null],
    'probes' => [
        OwnedDiffProbe::class,
        WeldedCallSitesProbe::class,
        LonelyFilesProbe::class,
        HotspotsProbe::class,
    ],
    'owned_diff' => [
        'paths' => ['app', 'bootstrap', 'config', 'database', 'routes', 'resources/js'],
        'extensions' => ['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'json'],
    ],
    'welds' => ['paths' => ['app'], 'facades' => [], 'max_sites' => 200],
    'lonely' => ['min_lines' => 30, 'limit' => 10],
    'churn' => ['since' => '24 months ago', 'limit' => 20],
    'exclude' => [],
];

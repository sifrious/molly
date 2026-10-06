<?php

return [
    // Complete application packs override bundled packs. Paths are relative to the application.
    'path' => 'resources/molly/seams',
    'max_file_bytes' => 262144,
    'max_pack_bytes' => 2097152,
    // Extra names select StepHandler implementations through Laravel's container.
    'handlers' => [],
    'step_timeout' => 300,
];

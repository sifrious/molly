<?php

arch('application actions do not depend on transport or presentation')
    ->expect('Sifrious\Molly\Actions')
    ->not->toUse([
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Console',
        'Sifrious\Molly\Livewire',
        'Laravel\Prompts',
        'Livewire',
    ]);

arch('bundled measurements do not depend on task execution or agents')
    ->expect('Sifrious\Molly\Complexity')
    ->not->toUse([
        'Sifrious\Molly\Actions',
        'Sifrious\Molly\Agents',
        'Sifrious\Molly\Models',
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Livewire',
    ]);

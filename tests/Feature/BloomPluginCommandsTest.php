<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * The Bloom plugin runs `php artisan molly:… --json` with literal argument arrays.
 * Every command and option it names must exist in this CLI.
 */

/** @return list<array{file: string, command: string, options: list<string>}> */
function bloomPluginArtisanCalls(): array
{
    $calls = [];
    foreach (File::allFiles(dirname(__DIR__, 2).'/bloom-plugin/Surfaces/Sources') as $file) {
        if ($file->getExtension() !== 'swift') {
            continue;
        }
        preg_match_all('/\[\s*"(molly:[a-z0-9:-]+)"(.*?)\]/s', $file->getContents(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all('/"--([a-z0-9-]+)/', $match[2], $options);
            $calls[] = ['file' => $file->getFilename(), 'command' => $match[1], 'options' => $options[1]];
        }
    }

    return $calls;
}

it('only calls Artisan commands and options that exist', function (): void {
    $calls = bloomPluginArtisanCalls();
    $commands = Artisan::all();

    expect($calls)->not->toBeEmpty();
    foreach ($calls as $call) {
        expect($commands)->toHaveKey($call['command']);
        $definition = $commands[$call['command']]->getDefinition();
        foreach ($call['options'] as $option) {
            expect($definition->hasOption($option))->toBeTrue("{$call['file']} passes --{$option} to {$call['command']}, which does not define it");
        }
    }
});

it('keeps the glossary screen read-only', function (): void {
    $glossaryCalls = array_filter(bloomPluginArtisanCalls(), fn (array $call): bool => $call['command'] === 'molly:glossary');

    expect($glossaryCalls)->not->toBeEmpty();
    foreach ($glossaryCalls as $call) {
        expect($call['options'])->toBe(['json']);
    }
});

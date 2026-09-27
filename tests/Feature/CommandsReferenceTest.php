<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

function mollyCommands(): array
{
    return collect(app(Kernel::class)->all())
        ->filter(fn ($command, string $name): bool => Str::startsWith($name, ['molly:', 'clever:']))
        ->all();
}

it('documents every molly and clever command in the commands reference', function (): void {
    $reference = file_get_contents(dirname(__DIR__, 2).'/docs/reference/commands.md');

    foreach (array_keys(mollyCommands()) as $name) {
        expect($reference)->toContain($name);
    }
});

it('gives every command --json except the internal molly:check, as the reference says', function (): void {
    $reference = file_get_contents(dirname(__DIR__, 2).'/docs/reference/commands.md');
    $withoutJson = collect(mollyCommands())
        ->reject(fn ($command): bool => $command->getDefinition()->hasOption('json'))
        ->keys()->all();

    expect($withoutJson)->toBe(['molly:check'])
        ->and($reference)->toContain('The exception is `molly:check`');
});

it('lists --allow-test-edits and --name only for the commands that accept them', function (): void {
    $commands = mollyCommands();
    $reference = file_get_contents(dirname(__DIR__, 2).'/docs/reference/commands.md');

    foreach (['molly:create', 'molly:import'] as $name) {
        expect($commands[$name]->getDefinition()->hasOption('allow-test-edits'))->toBeTrue()
            ->and($commands[$name]->getDefinition()->hasOption('name'))->toBeTrue();
    }
    expect($commands['molly:run']->getDefinition()->hasOption('allow-test-edits'))->toBeFalse()
        ->and($commands['molly:run']->getDefinition()->hasOption('name'))->toBeFalse()
        ->and($reference)->toContain('| `--allow-test-edits` | `create`, `import` |')
        ->and($reference)->toContain('| `--name=NAME` | `create`, `import` |');
});

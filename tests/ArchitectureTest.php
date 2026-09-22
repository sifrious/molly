<?php

use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\JsonDocument;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Tests\TestCase;

uses(TestCase::class);

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

arch('knowledge storage does not depend on agents or presentation')
    ->expect('Sifrious\Molly\Knowledge')
    ->not->toUse([
        'Sifrious\Molly\Agents',
        'Sifrious\Molly\Console',
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Livewire',
        'Laravel\Mcp',
        'Laravel\Prompts',
        'Livewire',
    ]);

arch('classification adapters do not depend on transport or presentation')
    ->expect('Sifrious\Molly\Classification')
    ->not->toUse([
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Console',
        'Sifrious\Molly\Livewire',
        'Laravel\Prompts',
        'Livewire',
    ]);

arch('cross-repository contracts do not depend on transport or presentation')
    ->expect('Sifrious\Molly\Contracts')
    ->not->toUse([
        'Sifrious\Molly\Actions',
        'Sifrious\Molly\Agents',
        'Sifrious\Molly\Console',
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Livewire',
        'Sifrious\Molly\Models',
        'Laravel\Mcp',
        'Laravel\Prompts',
        'Livewire',
    ]);

it('resolves collaborator-bearing actions from the Laravel container', function () {
    foreach ([CreateTask::class, VerifyChanges::class] as $action) {
        expect(app($action))->toBeInstanceOf($action);
    }
});

it('does not ban direct construction of contract values outside the container', function () {
    expect(DisplayStatus::Pending->value)->toBe('pending')
        ->and(LifecycleEventType::Created->value)->toBe('created')
        ->and(JsonDocument::encode(['schema' => 'test', 'ok' => true]))->toContain('"ok":true');
});

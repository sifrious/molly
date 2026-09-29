<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Console\WritesJson;
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

arch('MCP tools never call the actions that record a human decision')
    ->expect('Sifrious\Molly\Mcp')
    ->not->toUse([
        'Sifrious\Molly\Actions\ApproveTask',
        'Sifrious\Molly\Actions\LockProtectedTest',
        'Sifrious\Molly\Actions\RecordPullRequestOpened',
        'Sifrious\Molly\Actions\RecordMerged',
        'Sifrious\Molly\Actions\PublishGitHubIssueStatus',
        'Sifrious\Molly\Actions\HandOffTask',
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

arch('journal rendering stays free of filesystem and Eloquent access')
    ->expect('Sifrious\Molly\Journal\JournalRenderer')
    ->not->toUse([
        'Illuminate\Support\Facades\File',
        'Illuminate\Support\Facades\Storage',
        'Illuminate\Database',
        'Illuminate\Database\Eloquent',
        'Sifrious\Molly\Models',
        'Sifrious\Molly\Agents',
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Console',
        'Sifrious\Molly\Livewire',
        'Laravel\Mcp',
        'Laravel\Prompts',
        'Livewire',
    ]);

arch('model fit decisions do not depend on transport, agents, or presentation')
    ->expect('Sifrious\Molly\ModelFit')
    ->not->toUse([
        'Sifrious\Molly\Actions',
        'Sifrious\Molly\Agents',
        'Sifrious\Molly\Console',
        'Sifrious\Molly\Http',
        'Sifrious\Molly\Livewire',
        'Illuminate\Support\Facades\Http',
        'Illuminate\Support\Facades\Process',
        'Laravel\Prompts',
        'Livewire',
    ]);

arch('console transport does not own run completion decisions')
    ->expect('Sifrious\Molly\Console')
    ->not->toUse(['Sifrious\Molly\Actions\DecideRunCompletion']);

arch('http transport does not own run completion decisions')
    ->expect('Sifrious\Molly\Http')
    ->not->toUse(['Sifrious\Molly\Actions\DecideRunCompletion']);

arch('livewire transport does not own run completion decisions')
    ->expect('Sifrious\Molly\Livewire')
    ->not->toUse(['Sifrious\Molly\Actions\DecideRunCompletion']);

arch('mcp transport does not own run completion decisions')
    ->expect('Sifrious\Molly\Mcp')
    ->not->toUse(['Sifrious\Molly\Actions\DecideRunCompletion']);

arch('mcp tools answer through the laravel/mcp transport, never the console output')
    ->expect('Sifrious\Molly\Mcp')
    ->not->toUse(['Illuminate\Console', 'Symfony\Component\Console', 'Laravel\Prompts']);

it('prints the JSON document of every command with --json through writeJson', function () {
    $checked = [];
    $bypassing = [];
    foreach (Artisan::all() as $name => $command) {
        $class = new ReflectionClass($command);
        if (! str_starts_with($class->getName(), 'Sifrious\\Molly\\') || ! $command->getNativeDefinition()->hasOption('json')) {
            continue;
        }
        $source = '';
        for ($type = $class; $type !== false && str_starts_with($type->getName(), 'Sifrious\\Molly\\'); $type = $type->getParentClass()) {
            $source .= file_get_contents($type->getFileName());
        }
        $checked[] = $name;
        if (! in_array(WritesJson::class, class_uses_recursive($command), true) || ! str_contains($source, '$this->writeJson(')) {
            $bypassing[] = $name;
        }
    }

    expect(count($checked))->toBeGreaterThan(55)
        ->and($bypassing)->toBe([]);
});

it('encodes JSON in console code only for writeJson and for output that is not a --json document', function () {
    $encoding = [];
    foreach ([...File::allFiles(dirname(__DIR__).'/src/Console'), ...File::allFiles(dirname(__DIR__).'/src/Complexity/Console')] as $file) {
        $count = substr_count($file->getContents(), 'json_encode(');
        if ($count > 0) {
            $encoding[str_replace(dirname(__DIR__).'/', '', $file->getPathname())] = $count;
        }
    }
    ksort($encoding);

    // Anything else would print a JSON document without writeJson, where an agent session rewrites it.
    expect($encoding)->toBe([
        'src/Console/MollyChatCommand.php' => 1, // the --mcp-config argument Amp receives
        'src/Console/MollyCheckCommand.php' => 1, // the check envelope, written to a file
        'src/Console/MollyInspectCommand.php' => 1, // a note for people without --json
        'src/Console/MollyPreflightCommand.php' => 2, // values in the table for people without --json
        'src/Console/MollySettingsCommand.php' => 1, // a note for people without --json
        'src/Console/RunReport.php' => 1, // a value in the report for people
        'src/Console/WritesJson.php' => 1,
    ]);
});

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

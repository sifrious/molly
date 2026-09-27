<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->workspace = sys_get_temp_dir().'/molly-rename-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app/Services');
    File::put($this->workspace.'/app/Services/Greeter.php', "<?php\n");
    writeProtectedTest($this->workspace, 'tests/Feature/GreeterTest.php');
    config()->set('molly.knowledge.database', $this->workspace.'/.molly/knowledge.sqlite');
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

/** @return array<string, mixed> */
function greeterNeighborhood(string $workspace): array
{
    expect(Artisan::call('molly:project:index', ['--workspace' => $workspace, '--json' => true]))->toBe(0);
    Artisan::call('molly:project:query', ['concept' => 'greeter', '--workspace' => $workspace, '--depth' => 1, '--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('marks a changes edge unresolved when its file was moved away, without guessing the new path', function (): void {
    app(CreateTask::class)->handle('Make the greeter say hello.', $this->workspace, ['app/Services/Greeter.php'], 'tests/Feature/GreeterTest.php', nickname: 'greeter');

    $before = greeterNeighborhood($this->workspace);
    $changes = collect($before['edges'])->firstWhere('relation', 'changes');
    expect($changes['metadata'])->toBe([]);

    Process::path($this->workspace)->run(['git', 'mv', 'app/Services/Greeter.php', 'app/Services/Welcomer.php'])->throw();
    Process::path($this->workspace)->run(['git', '-c', 'user.name=Molly Test', '-c', 'user.email=molly@example.test', 'commit', '-qm', 'Rename Greeter'])->throw();

    $after = greeterNeighborhood($this->workspace);
    $changes = collect($after['edges'])->firstWhere('relation', 'changes');
    $file = collect($after['nodes'])->firstWhere('type', 'file');

    expect($changes['metadata'])->toBe(['status' => 'unresolved', 'reason' => 'target_missing', 'path' => 'app/Services/Greeter.php'])
        ->and($changes['sources'])->not->toBeEmpty()
        ->and($file['key'])->toBe('app/Services/Greeter.php')
        ->and($file['metadata'])->toBe(['exists' => false])
        ->and(collect($after['nodes'])->pluck('key')->all())->not->toContain('app/Services/Welcomer.php')
        ->and(collect($after['edges'])->firstWhere('relation', 'verified_by')['metadata'])->toBe([]);
});

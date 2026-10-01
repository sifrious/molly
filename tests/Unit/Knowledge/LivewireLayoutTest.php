<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Knowledge\LivewireLayout;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/molly-livewire-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root.'/vendor/composer');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function lockWithLivewire(string $root, string $version, string $bucket = 'packages'): void
{
    File::put($root.'/composer.lock', json_encode(['packages' => [], 'packages-dev' => [], $bucket => [['name' => 'livewire/livewire', 'version' => $version]]]));
}

it('uses the layout of the installed Livewire major version', function (string $version, string $namespace, string $directory) {
    lockWithLivewire($this->root, $version);

    expect(LivewireLayout::forWorkspace($this->root)->toArray())->toBe([
        'installed_version' => ltrim($version, 'v'),
        'class_namespace' => $namespace,
        'class_directory' => $directory,
        'view_directory' => 'resources/views/livewire',
    ]);
})->with([
    'Livewire 2' => ['v2.12.6', 'App\\Http\\Livewire', 'app/Http/Livewire'],
    'Livewire 3' => ['v3.6.4', 'App\\Livewire', 'app/Livewire'],
    'Livewire 4' => ['v4.4.7', 'App\\Livewire', 'app/Livewire'],
]);

it('reads a development dependency and falls back to vendor/composer/installed.json', function () {
    lockWithLivewire($this->root, 'v4.4.7', 'packages-dev');
    expect(LivewireLayout::forWorkspace($this->root)->installedVersion)->toBe('4.4.7');

    File::delete($this->root.'/composer.lock');
    File::put($this->root.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'livewire/livewire', 'version' => 'v2.12.6']]]));
    expect(LivewireLayout::forWorkspace($this->root)->classNamespace)->toBe('App\\Http\\Livewire');
});

it('uses the current default layout when Livewire is not installed', function () {
    File::put($this->root.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']], 'packages-dev' => []]));

    expect(LivewireLayout::forWorkspace($this->root)->toArray())->toMatchArray(['installed_version' => null, 'class_namespace' => 'App\\Livewire', 'class_directory' => 'app/Livewire']);
});

it('moves a component class from the other layout and names the view Livewire renders for it', function () {
    $current = new LivewireLayout('4.4.7', 'App\\Livewire', 'app/Livewire');
    $legacy = new LivewireLayout('2.12.6', 'App\\Http\\Livewire', 'app/Http/Livewire');

    expect($current->relocate('app/Http/Livewire/Admin/UserTable.php'))->toBe('app/Livewire/Admin/UserTable.php')
        ->and($current->relocate('app/Livewire/UserTable.php'))->toBeNull()
        ->and($current->relocate('app/Http/Controllers/AuthController.php'))->toBeNull()
        ->and($legacy->relocate('app/Livewire/UserTable.php'))->toBe('app/Http/Livewire/UserTable.php')
        ->and($current->viewFor('app/Livewire/Admin/UserTable.php'))->toBe('resources/views/livewire/admin/user-table.blade.php')
        ->and($current->viewFor('app/Http/Livewire/UserTable.php'))->toBeNull()
        ->and($legacy->viewFor('app/Http/Livewire/UserTable.php'))->toBe('resources/views/livewire/user-table.blade.php')
        ->and($current->viewFor('app/Livewire/Concerns/'))->toBeNull();
});

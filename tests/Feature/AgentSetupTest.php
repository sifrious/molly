<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\ConfigureAgent;
use Sifrious\Molly\Console\MollyChatCommand;
use Sifrious\Molly\Console\MollySetupCommand;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function (): void {
    $this->setupDirectory = sys_get_temp_dir().'/molly-setup-'.bin2hex(random_bytes(8));
    mkdir($this->setupDirectory);
    $this->app->useEnvironmentPath($this->setupDirectory);
    $this->app->loadEnvironmentFrom('.env');
    $this->app->make(Kernel::class)->registerCommand(new MollySetupCommand);
    $this->app->make(Kernel::class)->registerCommand(new MollyChatCommand);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://localhost:11434']]);
    $this->mock(ExecutableFinder::class)->shouldReceive('find')->with('amp')->andReturn('/test tools/amp');
    Process::fake();
});

afterEach(function (): void {
    File::deleteDirectory($this->setupDirectory);
});

it('saves an installed local model without changing unrelated environment settings', function (): void {
    file_put_contents($this->setupDirectory.'/.env', "APP_NAME=Example\nPRIVATE_VALUE=keep-me\nMOLLY_AGENT=amp\nMOLLY_LOCAL_MODEL=old\n");
    Http::fake(['localhost:11434/api/tags' => Http::response(['models' => [['name' => 'qwen:7b']]])]);
    $this->artisan('molly:setup', ['--agent' => 'ollama', '--model' => 'qwen:7b', '--json' => true])->assertSuccessful()->run();
    expect(file_get_contents($this->setupDirectory.'/.env'))->toBe("APP_NAME=Example\nPRIVATE_VALUE=keep-me\nMOLLY_AGENT=ollama\nMOLLY_LOCAL_MODEL=qwen:7b\n");
    Process::assertNothingRan();
});

it('rejects uninstalled or injected models without writing configuration', function (string $model): void {
    Http::fake(['localhost:11434/api/tags' => Http::response(['models' => [['name' => 'qwen:7b']]])]);
    $this->artisan('molly:setup', ['--agent' => 'ollama', '--model' => $model, '--json' => true])->assertFailed()->run();
    expect(file_exists($this->setupDirectory.'/.env'))->toBeFalse();
})->with(['missing', "qwen:7b\nOTHER=value", 'remote:cloud']);

it('rejects a remote Ollama endpoint before making a request', function (): void {
    config(['ai.providers.ollama.url' => 'https://example.com']);
    expect(fn () => app(ConfigureAgent::class)->models())->toThrow(RuntimeException::class, 'LOCAL_PROVIDER_INVALID');
    Http::assertNothingSent();
});

it('configures workspace Amp MCP with argument boundaries and never logs in from JSON', function (): void {
    $this->artisan('molly:setup', ['--agent' => 'amp', '--json' => true])->assertSuccessful()->run();
    Process::assertRan(fn ($process) => $process->command === ['/test tools/amp', 'mcp', 'add', 'molly', '--workspace', '--', PHP_BINARY, base_path('artisan'), 'mcp:start', 'molly'] && $process->path === base_path());
    Process::assertRanTimes(fn ($process) => true, 1);
    expect(file_get_contents($this->setupDirectory.'/.env'))->toBe("MOLLY_AGENT=amp\n");
});

it('does not save agent selection when Amp MCP setup fails', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $this->artisan('molly:setup', ['--agent' => 'amp', '--no-login' => true, '--json' => true])->assertFailed()->run();
    expect(file_exists($this->setupDirectory.'/.env'))->toBeFalse();
});

it('prints a session MCP configuration without launching chat in JSON mode', function (): void {
    $command = ['/test tools/amp', '--mcp-config', json_encode(['molly' => ['command' => PHP_BINARY, 'args' => [base_path('artisan'), 'mcp:start', 'molly']]], JSON_THROW_ON_ERROR)];
    $this->artisan('molly:chat', ['--json' => true])->expectsOutput(json_encode(['command' => $command, 'workspace' => base_path()], JSON_THROW_ON_ERROR))->assertSuccessful();
    Process::assertNothingRan();
});

it('does not launch chat without an interactive terminal', function (): void {
    $this->artisan('molly:chat', ['--no-interaction' => true])->assertFailed()->run();
    Process::assertNothingRan();
});

it('refuses to overwrite a symlinked environment file', function (): void {
    $target = $this->setupDirectory.'/target';
    file_put_contents($target, 'APP_NAME=keep');
    symlink($target, $this->setupDirectory.'/.env');
    expect(fn () => app(ConfigureAgent::class)->handle('amp'))->toThrow(RuntimeException::class, 'regular file');
    expect(file_get_contents($target))->toBe('APP_NAME=keep');
});

it('requires an explicit agent for unattended setup', function (): void {
    $this->artisan('molly:setup', ['--json' => true])->assertFailed()->run();
    Process::assertNothingRan();
    Http::assertNothingSent();
    expect(file_exists($this->setupDirectory.'/.env'))->toBeFalse();
});

it('leaves configuration untouched when Ollama redirects elsewhere', function (): void {
    Http::fake(['localhost:11434/api/tags' => Http::response('', 302, ['Location' => 'https://example.com'])]);
    $this->artisan('molly:setup', ['--agent' => 'ollama', '--model' => 'qwen:7b', '--json' => true])->assertFailed()->run();
    expect(file_exists($this->setupDirectory.'/.env'))->toBeFalse();
    Http::assertSentCount(1);
});

it('rejects environment layouts that would change unrelated values', function (string $contents): void {
    $path = $this->setupDirectory.'/.env';
    file_put_contents($path, $contents);
    config(['molly.agent' => 'ollama']);
    expect(fn () => app(ConfigureAgent::class)->handle('amp'))->toThrow(RuntimeException::class, 'ENV_LAYOUT_UNSUPPORTED');
    expect(file_get_contents($path))->toBe($contents)->and(config('molly.agent'))->toBe('ollama');
})->with([
    'assignment inside a quoted value' => "OTHER=\"first\nMOLLY_AGENT=unrelated text\nlast\"\nMOLLY_AGENT=ollama\n",
    'an unrelated interpolated value' => "MOLLY_AGENT=ollama\nOTHER=\"\${MOLLY_AGENT}\"\n",
]);

it('rejects malformed and duplicate environment entries without writing or exposing their contents', function (string $contents, string $classification): void {
    $path = $this->setupDirectory.'/.env';
    file_put_contents($path, $contents);
    try {
        app(ConfigureAgent::class)->handle('amp');
        test()->fail('Setup should reject this environment file.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain($classification)->not->toContain('private-value');
    }
    expect(file_get_contents($path))->toBe($contents);
})->with([
    'malformed value' => ["OTHER=private-value invalid\nMOLLY_AGENT=ollama\n", 'ENV_INVALID'],
    'unfinished quote' => ["OTHER=\"private-value\nMOLLY_AGENT=ollama\n", 'ENV_LAYOUT_UNSUPPORTED'],
    'duplicate key' => ["MOLLY_AGENT=private-value\nMOLLY_AGENT=ollama\n", 'ENV_DUPLICATE_KEYS'],
    'indented assignment' => ["  MOLLY_AGENT=private-value\n", 'ENV_DUPLICATE_KEYS'],
]);

it('preserves supported quoted multiline values while saving the chosen agent', function (): void {
    $path = $this->setupDirectory.'/.env';
    file_put_contents($path, "OTHER=\"first\nlast\"\nMOLLY_AGENT=ollama\n");
    app(ConfigureAgent::class)->handle('amp');
    expect(file_get_contents($path))->toBe("OTHER=\"first\nlast\"\nMOLLY_AGENT=amp\n");
});

<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Execution\Sandbox;

beforeEach(function () {
    config(['molly.sandbox.allow_unsafe' => false]);
    $this->sandbox = app(Sandbox::class);
    if (! $this->sandbox->available()) {
        $this->markTestSkipped('Landlock and user/network namespaces are not available.');
    }
    $this->root = sys_get_temp_dir().'/molly-sandbox-'.Str::uuid();
    File::ensureDirectoryExists($this->root.'/workspace/app');
    File::ensureDirectoryExists($this->root.'/workspace/tests');
    File::ensureDirectoryExists($this->root.'/outside');
    File::ensureDirectoryExists($this->root.'/evidence');
    File::put($this->root.'/workspace/app/Greeting.php', 'ok');
    File::put($this->root.'/workspace/tests/GreetingTest.php', 'test');
    File::put($this->root.'/outside/secret.txt', 'secret');
});

afterEach(function () {
    if (isset($this->root)) {
        File::deleteDirectory($this->root);
    }
});

it('lets the writer change only the allowed file', function () {
    $script = $this->root.'/probe.php';
    File::put($script, <<<'PHP'
<?php
error_reporting(0);
$workspace = $argv[1];
$outside = $argv[2];
$ok = file_put_contents($workspace.'/app/Greeting.php', 'changed');
$protected = @file_put_contents($workspace.'/tests/GreetingTest.php', 'pwn');
$unlisted = @file_put_contents($workspace.'/app/pwned.php', 'pwn');
$secret = @file_get_contents($outside.'/secret.txt');
echo json_encode(['ok' => $ok !== false, 'protected' => $protected !== false, 'unlisted' => $unlisted !== false, 'secret' => $secret]);
PHP);

    $result = $this->sandbox->run(
        $this->root.'/workspace',
        ['app/Greeting.php'],
        [PHP_BINARY, $script, $this->root.'/workspace', $this->root.'/outside'],
        $this->root.'/evidence',
        10,
    );
    $payload = json_decode($result['output'], true);

    expect($result['exit_code'])->toBe(0)
        ->and($payload)->toMatchArray(['ok' => true, 'protected' => false, 'unlisted' => false, 'secret' => false])
        ->and(File::get($this->root.'/workspace/app/Greeting.php'))->toBe('changed')
        ->and(File::get($this->root.'/workspace/tests/GreetingTest.php'))->toBe('test')
        ->and(File::exists($this->root.'/workspace/app/pwned.php'))->toBeFalse();
});

it('runs Pest inside the sandbox and still produces JUnit evidence', function () {
    symlink(dirname(__DIR__, 2).'/vendor', $this->root.'/workspace/vendor');
    File::put($this->root.'/workspace/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="Workspace">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);
    File::put($this->root.'/workspace/tests/GreetingTest.php', <<<'PHP'
<?php
it('exists', function () {
    expect(true)->toBeTrue();
});
PHP);

    $result = app(VerifyChanges::class)->handle(
        $this->root.'/workspace',
        'tests/GreetingTest.php',
        $this->root.'/evidence',
    );

    expect($result['status'])->toBe('passed')
        ->and($result['tests'])->toBe(1)
        ->and($result['junit'])->toBeFile();
});

it('does not inherit removed credentials or default network access', function () {
    $script = $this->root.'/probe.php';
    File::put($script, <<<'PHP'
<?php
error_reporting(0);
$fp = @fsockopen('1.1.1.1', 443, $errno, $errstr, 1);
echo json_encode([
    'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
    'net' => $fp !== false,
]);
PHP);

    putenv('AWS_SECRET_ACCESS_KEY=test-secret');
    $_ENV['AWS_SECRET_ACCESS_KEY'] = 'test-secret';

    $result = $this->sandbox->run(
        $this->root.'/workspace',
        ['app/Greeting.php'],
        [PHP_BINARY, $script],
        $this->root.'/evidence',
        10,
        ['AWS_SECRET_ACCESS_KEY' => false],
    );
    $payload = json_decode($result['output'], true);

    expect($result['exit_code'])->toBe(0)
        ->and($payload['secret'])->toBeFalse()
        ->and($payload['net'])->toBeFalse();
});

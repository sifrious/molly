<?php

use Illuminate\Filesystem\Filesystem;
use Sifrious\Molly\Actions\VerifyChanges;

it('lets workspace PHPUnit and Dotenv settings replace inherited host settings', function (): void {
    $directory = sys_get_temp_dir().'/molly-env-'.bin2hex(random_bytes(8));
    mkdir($directory.'/host', 0700, true);
    mkdir($directory.'/workspace/tests', 0700, true);
    symlink(dirname(__DIR__, 2).'/vendor', $directory.'/workspace/vendor');
    file_put_contents($directory.'/host/.env', "APP_ENV=local\nSESSION_DRIVER=database\nMOLLY_ENV_TEST_VALUE=host\n");
    file_put_contents($directory.'/workspace/.env', "MOLLY_ENV_TEST_VALUE=workspace\n");
    file_put_contents($directory.'/workspace/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit><php><env name="APP_ENV" value="testing"/><env name="SESSION_DRIVER" value="array"/></php></phpunit>
XML);
    file_put_contents($directory.'/workspace/tests/EnvironmentTest.php', <<<'PHPTEST'
<?php
use PHPUnit\Framework\TestCase;
final class EnvironmentTest extends TestCase
{
    public function test_uses_workspace_settings(): void
    {
        Dotenv\Dotenv::createImmutable(getcwd())->load();
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('array', getenv('SESSION_DRIVER'));
        $this->assertSame('workspace', $_ENV['MOLLY_ENV_TEST_VALUE']);
        $this->assertNotFalse(getenv('PATH'));
    }
}
PHPTEST);
    $oldPath = app()->environmentPath();
    $oldFile = app()->environmentFile();
    $oldVariables = [];
    foreach (['APP_ENV' => 'local', 'SESSION_DRIVER' => 'database', 'MOLLY_ENV_TEST_VALUE' => 'host'] as $key => $value) {
        $oldVariables[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    app()->useEnvironmentPath($directory.'/host')->loadEnvironmentFrom('.env');

    try {
        $result = app(VerifyChanges::class)->handle($directory.'/workspace', 'tests/EnvironmentTest.php', $directory.'/evidence');

        expect($result['status'])->toBe('passed', $result['output']);
        expect($result['assertions'])->toBe(4);
        expect(getenv('SESSION_DRIVER'))->toBe('database');
    } finally {
        app()->useEnvironmentPath($oldPath)->loadEnvironmentFrom($oldFile);
        foreach ($oldVariables as $key => [$process, $env, $server]) {
            putenv($process === false ? $key : $key.'='.$process);
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
        (new Filesystem)->deleteDirectory($directory);
    }
});

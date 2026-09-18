<?php

use Sifrious\Molly\Execution\Landlock;

ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

$policyPath = $argv[1] ?? '';
if ($policyPath === '' || ! is_file($policyPath)) {
    fwrite(STDERR, "SANDBOX_POLICY_INVALID\n");
    exit(2);
}

$autoload = [
    dirname(__DIR__, 2).'/vendor/autoload.php',
    dirname(__DIR__, 4).'/autoload.php',
];
$loaded = false;
foreach ($autoload as $path) {
    if (is_file($path)) {
        require $path;
        $loaded = true;
        break;
    }
}
if (! $loaded) {
    fwrite(STDERR, "SANDBOX_AUTOLOAD_MISSING\n");
    exit(2);
}

$policy = json_decode((string) file_get_contents($policyPath), true);
if (! is_array($policy) || ! is_string($policy['workspace'] ?? null) || ! is_array($policy['command'] ?? null)) {
    fwrite(STDERR, "SANDBOX_POLICY_INVALID\n");
    exit(2);
}

if (function_exists('pcntl_unshare')) {
    if (defined('CLONE_NEWUSER')) {
        pcntl_unshare(CLONE_NEWUSER);
    }
    if (! ($policy['network'] ?? false) && defined('CLONE_NEWNET')) {
        pcntl_unshare(CLONE_NEWNET);
    }
}

$paths = [];
foreach (['/usr', '/bin', '/lib', '/lib64', '/etc', '/dev', '/proc'] as $host) {
    if (file_exists($host)) {
        $paths[] = ['path' => $host, 'access' => 'ro'];
    }
}
$paths[] = ['path' => $policy['workspace'], 'access' => 'ro'];
foreach ($policy['read'] ?? [] as $read) {
    if (is_string($read) && file_exists($read)) {
        $paths[] = ['path' => $read, 'access' => 'ro'];
    }
}
foreach ([$policy['evidence'] ?? null, $policy['tmp'] ?? null] as $writableDir) {
    if (is_string($writableDir) && is_dir($writableDir)) {
        $paths[] = ['path' => $writableDir, 'access' => 'rw'];
    }
}
foreach ($policy['writable'] ?? [] as $writable) {
    if (! is_string($writable) || $writable === '') {
        continue;
    }
    $absolute = $writable[0] === '/' ? $writable : $policy['workspace'].'/'.$writable;
    if (file_exists($absolute)) {
        $paths[] = ['path' => $absolute, 'access' => 'rw'];
    }
}

Landlock::restrict($paths);

$command = array_values($policy['command']);
$binary = array_shift($command);
if (! is_string($binary) || $binary === '') {
    fwrite(STDERR, "SANDBOX_POLICY_INVALID\n");
    exit(2);
}

$process = proc_open(
    array_merge([$binary], $command),
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $policy['workspace'],
);
if (! is_resource($process)) {
    fwrite(STDERR, "SANDBOX_EXEC_FAILED\n");
    exit(2);
}

exit(proc_close($process));

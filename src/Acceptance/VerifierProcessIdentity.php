<?php

namespace Sifrious\Molly\Acceptance;

use Illuminate\Support\Facades\Process;

final readonly class VerifierProcessIdentity
{
    public function __construct(
        public string $phpBinary,
        public int $pid,
        public ?int $parentPid,
        public ?string $parentName,
        public ?string $bundleId,
        public ?string $applicationName,
    ) {}

    public static function capture(): self
    {
        $pid = getmypid() ?: 0;
        $parent = function_exists('posix_getppid') ? posix_getppid() : null;
        $parentName = null;
        $bundleId = null;
        $application = null;
        $executable = null;

        if (is_int($parent) && $parent > 0) {
            $comm = @file_get_contents('/proc/'.$parent.'/comm');
            if (is_string($comm) && trim($comm) !== '') {
                $parentName = trim($comm);
            }
            $linked = @readlink('/proc/'.$parent.'/exe');
            if (is_string($linked) && $linked !== '') {
                $executable = $linked;
            }
            if ($parentName === null && PHP_OS_FAMILY === 'Darwin') {
                $result = Process::timeout(5)->run(['ps', '-o', 'comm=', '-p', (string) $parent]);
                if ($result->successful() && trim($result->output()) !== '') {
                    $parentName = trim($result->output());
                }
            }
        }

        if (is_string($executable) && str_contains($executable, '.app/Contents/MacOS/')) {
            $plist = preg_replace('#/Contents/MacOS/.*$#', '/Contents/Info.plist', $executable);
            if (is_string($plist) && is_file($plist)) {
                $xml = (string) file_get_contents($plist);
                if (preg_match('/<key>CFBundleIdentifier<\/key>\s*<string>([^<]+)<\/string>/', $xml, $match) === 1) {
                    $bundleId = $match[1];
                }
                if (preg_match('/<key>CFBundleName<\/key>\s*<string>([^<]+)<\/string>/', $xml, $match) === 1) {
                    $application = $match[1];
                }
            }
        }

        return new self(PHP_BINARY, $pid, $parent, $parentName, $bundleId, $application ?? $parentName);
    }

    public function label(): string
    {
        $app = $this->applicationName ?? $this->parentName ?? 'the process that launched PHP';
        $bundle = $this->bundleId === null ? '' : ' ('.$this->bundleId.')';

        return $app.$bundle.' running '.$this->phpBinary.' pid '.$this->pid;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'php_binary' => $this->phpBinary,
            'pid' => $this->pid,
            'parent_pid' => $this->parentPid,
            'parent_name' => $this->parentName,
            'bundle_id' => $this->bundleId,
            'application_name' => $this->applicationName,
        ];
    }
}

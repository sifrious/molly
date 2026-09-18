<?php

namespace Sifrious\Molly\Execution;

use FFI;
use RuntimeException;

final class Landlock
{
    private const SYS_CREATE = 444;

    private const SYS_ADD = 445;

    private const SYS_RESTRICT = 446;

    private const RULE_PATH_BENEATH = 1;

    private const CREATE_VERSION = 1;

    private const PR_SET_NO_NEW_PRIVS = 38;

    private const O_PATH = 010000000;

    private const O_CLOEXEC = 02000000;

    private const FS_EXECUTE = 1 << 0;

    private const FS_WRITE_FILE = 1 << 1;

    private const FS_READ_FILE = 1 << 2;

    private const FS_READ_DIR = 1 << 3;

    private const FS_REMOVE_DIR = 1 << 4;

    private const FS_REMOVE_FILE = 1 << 5;

    private const FS_MAKE_DIR = 1 << 7;

    private const FS_MAKE_REG = 1 << 8;

    private const FS_MAKE_SYM = 1 << 12;

    private const FS_REFER = 1 << 13;

    private const DIR_RO = self::FS_EXECUTE | self::FS_READ_FILE | self::FS_READ_DIR;

    private const DIR_RW = self::DIR_RO | self::FS_WRITE_FILE | self::FS_REMOVE_FILE | self::FS_MAKE_REG | self::FS_MAKE_DIR | self::FS_REMOVE_DIR | self::FS_MAKE_SYM | self::FS_REFER;

    private const FILE_RO = self::FS_EXECUTE | self::FS_READ_FILE;

    private const FILE_RW = self::FILE_RO | self::FS_WRITE_FILE;

    public static function abi(): ?int
    {
        if (! extension_loaded('ffi')) {
            return null;
        }

        try {
            $abi = self::sys3()->syscall(self::SYS_CREATE, null, 0, self::CREATE_VERSION);

            return $abi >= 1 ? (int) $abi : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{path: string, access: 'ro'|'rw'}>  $paths
     */
    public static function restrict(array $paths): void
    {
        if (self::abi() === null) {
            throw new RuntimeException('SANDBOX_UNAVAILABLE: Landlock is not available on this host.');
        }

        $libc = self::libc();
        $attrT = FFI::cdef('typedef struct { uint64_t handled_access_fs; } landlock_ruleset_attr;');
        $attr = $attrT->new('landlock_ruleset_attr');
        $attr->handled_access_fs = self::DIR_RW;
        $ruleset = self::sys3()->syscall(self::SYS_CREATE, FFI::addr($attr), FFI::sizeof($attr), 0);
        if ($ruleset < 0) {
            throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly could not create a Landlock ruleset.');
        }

        try {
            $ruleT = FFI::cdef('typedef struct { uint64_t allowed_access; int32_t parent_fd; } landlock_path_beneath_attr;');
            foreach ($paths as $entry) {
                $path = $entry['path'];
                if (! file_exists($path)) {
                    throw new RuntimeException('SANDBOX_PATH_MISSING: '.$path);
                }
                $access = is_dir($path)
                    ? ($entry['access'] === 'rw' ? self::DIR_RW : self::DIR_RO)
                    : ($entry['access'] === 'rw' ? self::FILE_RW : self::FILE_RO);
                $fd = $libc->open($path, self::O_PATH | self::O_CLOEXEC);
                if ($fd < 0) {
                    throw new RuntimeException('SANDBOX_PATH_MISSING: '.$path);
                }
                try {
                    $rule = $ruleT->new('landlock_path_beneath_attr');
                    $rule->allowed_access = $access;
                    $rule->parent_fd = $fd;
                    $added = self::sys4()->syscall(self::SYS_ADD, $ruleset, self::RULE_PATH_BENEATH, FFI::addr($rule), 0);
                    if ($added < 0) {
                        throw new RuntimeException('SANDBOX_RULE_INVALID: '.$path);
                    }
                } finally {
                    $libc->close($fd);
                }
            }

            if ($libc->prctl(self::PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) !== 0) {
                throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly could not set no_new_privs.');
            }
            if (self::sys2()->syscall(self::SYS_RESTRICT, $ruleset, 0) < 0) {
                throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly could not restrict the process with Landlock.');
            }
        } finally {
            $libc->close($ruleset);
        }
    }

    private static function libc(): FFI
    {
        return FFI::cdef(<<<'C'
            int open(const char *pathname, int flags);
            int close(int fd);
            int prctl(int option, unsigned long arg2, unsigned long arg3, unsigned long arg4, unsigned long arg5);
        C, 'libc.so.6');
    }

    private static function sys3(): FFI
    {
        return FFI::cdef('long syscall(long n, void *a1, size_t a2, unsigned int a3);', 'libc.so.6');
    }

    private static function sys4(): FFI
    {
        return FFI::cdef('long syscall(long n, long a1, long a2, void *a3, unsigned long a4);', 'libc.so.6');
    }

    private static function sys2(): FFI
    {
        return FFI::cdef('long syscall(long n, long a1, unsigned long a2);', 'libc.so.6');
    }
}

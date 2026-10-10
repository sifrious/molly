<?php

namespace Sifrious\Molly\Acceptance;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads Screen Recording and Accessibility through public macOS APIs.
 *
 * The probe prints two booleans. It does not capture the screen, reset TCC,
 * or write a permission. A false result is not a grant and is not a Molly failure.
 */
final class MacOsTccProbe
{
    /** @return array{screen_recording: bool, accessibility: bool}|null null when this is not macOS */
    public function read(bool $request): ?array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }

        $script = tempnam(sys_get_temp_dir(), 'molly-tcc-');
        if ($script === false) {
            throw new RuntimeException('PERMISSION_PROBE_FAILED: Molly could not store the macOS permission probe.');
        }
        $path = $script.'.swift';
        if (! rename($script, $path)) {
            @unlink($script);
            throw new RuntimeException('PERMISSION_PROBE_FAILED: Molly could not store the macOS permission probe.');
        }

        try {
            file_put_contents($path, $request ? $this->requestSource() : $this->inspectSource());
            $result = Process::timeout($request ? 180 : 20)->run(['swift', $path]);
        } finally {
            @unlink($path);
        }

        if (! $result->successful()) {
            throw new RuntimeException('PERMISSION_PROBE_FAILED: The macOS permission probe could not run. '.trim($result->errorOutput()));
        }

        $decoded = json_decode(trim($result->output()), true);
        if (! is_array($decoded) || ! is_bool($decoded['screen_recording'] ?? null) || ! is_bool($decoded['accessibility'] ?? null)) {
            throw new RuntimeException('PERMISSION_PROBE_FAILED: The macOS permission probe returned an unreadable result.');
        }

        return [
            'screen_recording' => $decoded['screen_recording'],
            'accessibility' => $decoded['accessibility'],
        ];
    }

    private function inspectSource(): string
    {
        return <<<'SWIFT'
        import CoreGraphics
        import ApplicationServices
        let screen = CGPreflightScreenCaptureAccess()
        let access = AXIsProcessTrusted()
        print("{\"screen_recording\":\(screen ? "true" : "false"),\"accessibility\":\(access ? "true" : "false")}")
        SWIFT;
    }

    private function requestSource(): string
    {
        return <<<'SWIFT'
        import CoreGraphics
        import ApplicationServices
        let screen = CGRequestScreenCaptureAccess()
        let key = kAXTrustedCheckOptionPrompt.takeUnretainedValue() as String
        let access = AXIsProcessTrustedWithOptions([key: true] as CFDictionary)
        print("{\"screen_recording\":\(screen ? "true" : "false"),\"accessibility\":\(access ? "true" : "false")}")
        SWIFT;
    }
}

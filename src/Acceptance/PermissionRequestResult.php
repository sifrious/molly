<?php

namespace Sifrious\Molly\Acceptance;

final readonly class PermissionRequestResult
{
    /**
     * @param  list<string>  $settingsOpened
     */
    public function __construct(
        public VerifierPermissionSnapshot $before,
        public VerifierPermissionSnapshot $after,
        public bool $requestAttempted,
        public array $settingsOpened,
        public bool $permissionGrantedByCli,
        public bool $userActionRequired,
        public string $message,
    ) {}

    /** @param  list<string>  $settingsOpened */
    public static function fromInspection(
        VerifierPermissionSnapshot $before,
        VerifierPermissionSnapshot $after,
        bool $requestAttempted,
        array $settingsOpened,
    ): self {
        $after = $requestAttempted ? $after->denyWhereRequestDidNotGrant() : $after;
        $newlyGranted = false;
        foreach (PermissionKind::cases() as $kind) {
            if (! $before->state($kind)->isGranted() && $after->state($kind)->isGranted()) {
                $newlyGranted = true;
            }
        }

        return new self(
            $before,
            $after,
            $requestAttempted,
            array_values($settingsOpened),
            $requestAttempted && $newlyGranted,
            $after->needsUserAction(),
            self::message($before, $after, $requestAttempted, $settingsOpened, $requestAttempted && $newlyGranted),
        );
    }

    /** @param  list<string>  $settingsOpened */
    private static function message(
        VerifierPermissionSnapshot $before,
        VerifierPermissionSnapshot $after,
        bool $requestAttempted,
        array $settingsOpened,
        bool $grantedByCli,
    ): string {
        $parts = [];
        foreach (PermissionKind::cases() as $kind) {
            $parts[] = $kind->label().' is '.$after->state($kind)->value.' for '.$after->process->label();
        }
        $text = implode('. ', $parts).'.';
        if ($grantedByCli) {
            return $text.' macOS reports a permission granted after the request. Molly did not write the TCC database.';
        }
        if (! $after->needsUserAction()) {
            return $after->screenRecording === PermissionState::Unsupported
                ? $text.' This operating system has no Screen Recording or Accessibility permission to grant. Native UI checks stay blocked in the verifier environment.'
                : $text.' Both permissions are granted.';
        }
        if ($requestAttempted) {
            $text .= ' Molly requested the missing permission. The CLI did not grant it.';
        } else {
            $text .= ' Molly did not request the permission during this run, and it cannot grant it.';
        }
        if ($before->platform === 'Darwin') {
            $urls = [];
            foreach (PermissionKind::cases() as $kind) {
                if (! $after->state($kind)->isGranted() && $after->state($kind) !== PermissionState::Unsupported) {
                    $urls[] = $kind->label().': '.$kind->settingsUrl();
                }
            }
            if ($urls !== []) {
                $text .= ' Open '.implode(' and ', $urls).'.';
            }
            if ($settingsOpened !== []) {
                $text .= ' Molly opened '.implode(', ', $settingsOpened).'.';
            }
        }

        return $text.' A check blocked for this reason is BLOCKED_VERIFIER_PERMISSION, not a Molly product failure.';
    }
}

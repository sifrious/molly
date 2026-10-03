<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use Sifrious\Molly\Contracts\JsonDocument;

final readonly class VerifierPermissionSnapshot
{
    public const SCHEMA = 'molly.verifier-permission-snapshot/1';

    public function __construct(
        public PermissionState $screenRecording,
        public PermissionState $accessibility,
        public VerifierProcessIdentity $process,
        public string $platform,
        public ?string $macosVersion,
        public string $machine,
        public DateTimeImmutable $inspectedAt,
    ) {}

    public static function unsupported(VerifierProcessIdentity $process, ?DateTimeImmutable $at = null): self
    {
        return new self(
            PermissionState::Unsupported,
            PermissionState::Unsupported,
            $process,
            PHP_OS_FAMILY,
            null,
            php_uname('n'),
            $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public function state(PermissionKind $kind): PermissionState
    {
        return match ($kind) {
            PermissionKind::ScreenRecording => $this->screenRecording,
            PermissionKind::Accessibility => $this->accessibility,
        };
    }

    /** @param  list<PermissionKind>  $required */
    public function missing(array $required): array
    {
        return array_values(array_filter(
            $required,
            fn (PermissionKind $kind): bool => ! $this->state($kind)->isGranted(),
        ));
    }

    public function supportsRequest(): bool
    {
        return $this->platform === 'Darwin'
            && ($this->screenRecording !== PermissionState::Unsupported || $this->accessibility !== PermissionState::Unsupported);
    }

    public function needsUserAction(): bool
    {
        foreach (PermissionKind::cases() as $kind) {
            $state = $this->state($kind);
            if ($state === PermissionState::Denied || $state === PermissionState::NotDetermined) {
                return true;
            }
        }

        return false;
    }

    /**
     * A completed request that the OS still does not report as granted is denied.
     * Granted and unsupported states stay as the inspector reported them.
     */
    public function denyWhereRequestDidNotGrant(): self
    {
        $map = fn (PermissionState $state): PermissionState => match ($state) {
            PermissionState::Granted, PermissionState::Unsupported => $state,
            PermissionState::Denied, PermissionState::NotDetermined => PermissionState::Denied,
        };

        return new self(
            $map($this->screenRecording),
            $map($this->accessibility),
            $this->process,
            $this->platform,
            $this->macosVersion,
            $this->machine,
            $this->inspectedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'screen_recording' => $this->screenRecording->value,
            'accessibility' => $this->accessibility->value,
            'process' => $this->process->toArray(),
            'platform' => $this->platform,
            'macos_version' => $this->macosVersion,
            'machine' => $this->machine,
            'inspected_at' => JsonDocument::formatTime($this->inspectedAt),
        ];
    }
}

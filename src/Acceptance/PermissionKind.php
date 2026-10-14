<?php

namespace Sifrious\Molly\Acceptance;

enum PermissionKind: string
{
    case ScreenRecording = 'screen_recording';
    case Accessibility = 'accessibility';

    public function label(): string
    {
        return match ($this) {
            self::ScreenRecording => 'Screen Recording',
            self::Accessibility => 'Accessibility',
        };
    }

    public function settingsUrl(): string
    {
        return match ($this) {
            self::ScreenRecording => 'x-apple.systempreferences:com.apple.settings.PrivacySecurity.extension?Privacy_ScreenCapture',
            self::Accessibility => 'x-apple.systempreferences:com.apple.settings.PrivacySecurity.extension?Privacy_Accessibility',
        };
    }
}

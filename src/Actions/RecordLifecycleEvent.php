<?php

namespace Sifrious\Molly\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Sifrious\Molly\Contracts\LifecycleEvent;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Contracts\LifecycleLog;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;
use Throwable;

class RecordLifecycleEvent
{
    public function __construct(private SecretRedactor $redactor) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(string $workspace, LifecycleEventType $type, string $taskId, ?string $runId = null, array $payload = [], ?string $eventId = null): bool
    {
        $event = LifecycleEvent::make(
            $type,
            $eventId ?? (string) Str::uuid(),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $taskId,
            $runId,
            $this->redactor->value($payload, $workspace),
        );

        $log = $this->load($workspace);
        if (! $log->record($event)) {
            return false;
        }

        $this->write($workspace, $event);

        return true;
    }

    public function load(string $workspace): LifecycleLog
    {
        $log = new LifecycleLog;
        $path = $this->path($workspace);
        if (! is_file($path)) {
            return $log;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $log->recordJson($line);
            } catch (Throwable) {
                continue;
            }
        }

        return $log;
    }

    private function write(string $workspace, LifecycleEvent $event): void
    {
        $root = (new Workspace($workspace))->path;
        $path = Directory::molly($root, 'lifecycle.jsonl');
        Directory::ensure(dirname($path), 0700);
        $mask = umask(0077);
        try {
            if (file_put_contents($path, $event->toJson()."\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('LIFECYCLE_UNWRITABLE: Molly could not record the lifecycle event.');
            }
        } finally {
            umask($mask);
        }
    }

    private function path(string $workspace): string
    {
        return (new Workspace($workspace))->path.'/.molly/lifecycle.jsonl';
    }
}

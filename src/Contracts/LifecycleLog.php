<?php

namespace Sifrious\Molly\Contracts;

final class LifecycleLog
{
    /** @var array<string, LifecycleEvent> */
    private array $events = [];

    /** @var list<string> */
    private array $order = [];

    public function record(LifecycleEvent $event): bool
    {
        if (isset($this->events[$event->eventId])) {
            return false;
        }

        $this->events[$event->eventId] = $event;
        $this->order[] = $event->eventId;

        return true;
    }

    public function recordJson(string $json): bool
    {
        return $this->record(LifecycleEvent::fromJson($json));
    }

    /** @return list<LifecycleEvent> */
    public function events(?string $taskId = null): array
    {
        $events = [];
        foreach ($this->order as $eventId) {
            $event = $this->events[$eventId];
            if ($taskId === null || $event->taskId === $taskId) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function displayStatus(string $taskId): DisplayStatus
    {
        $status = DisplayStatus::Pending;

        foreach ($this->events($taskId) as $event) {
            $type = $event->type();
            if ($type === null) {
                continue;
            }

            $status = match ($type) {
                LifecycleEventType::Created => DisplayStatus::Pending,
                LifecycleEventType::WorkspacePrepared => DisplayStatus::Preparing,
                LifecycleEventType::DispatchRequested,
                LifecycleEventType::AgentStarted,
                LifecycleEventType::ProposalReceived,
                LifecycleEventType::EditsAccepted,
                LifecycleEventType::EditsRejected,
                LifecycleEventType::VerificationStarted,
                LifecycleEventType::VerificationFinished,
                LifecycleEventType::RetryScheduled,
                LifecycleEventType::Recovered => DisplayStatus::Running,
                LifecycleEventType::ApprovalRequested => DisplayStatus::AwaitingApproval,
                LifecycleEventType::ApprovalResolved => DisplayStatus::Approved,
                LifecycleEventType::PullRequestOpened => DisplayStatus::AwaitingApproval,
                LifecycleEventType::HandedOff => DisplayStatus::HandedOff,
                LifecycleEventType::Stopped => DisplayStatus::Stopped,
                LifecycleEventType::Failed => DisplayStatus::Failed,
                LifecycleEventType::Merged => DisplayStatus::Merged,
            };
        }

        return $status;
    }

    public function dispatchDecision(string $taskId, string $runId): DispatchDecision
    {
        $requested = false;
        $accepted = false;

        foreach ($this->events($taskId) as $event) {
            if ($event->runId !== $runId) {
                continue;
            }

            $type = $event->type();
            if ($type === LifecycleEventType::DispatchRequested) {
                $requested = true;
            }
            if ($type === LifecycleEventType::AgentStarted) {
                $accepted = true;
            }
        }

        if ($accepted) {
            return DispatchDecision::AlreadyAccepted;
        }

        if ($requested) {
            return DispatchDecision::Unconfirmed;
        }

        return DispatchDecision::Needed;
    }

    public function contains(string $eventId): bool
    {
        return isset($this->events[$eventId]);
    }
}

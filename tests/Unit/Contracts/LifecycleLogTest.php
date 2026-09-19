<?php

use Sifrious\Molly\Contracts\DispatchDecision;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\LifecycleEvent;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Contracts\LifecycleLog;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

function lifecycleEvent(LifecycleEventType $type, string $eventId, ?string $runId = ContractFixtures::RUN_ID): LifecycleEvent
{
    return LifecycleEvent::make(
        $type,
        $eventId,
        new DateTimeImmutable('2026-09-18T12:00:00Z', new DateTimeZone('UTC')),
        ContractFixtures::TASK_ID,
        $runId,
    );
}

it('round-trips known lifecycle events', function (LifecycleEventType $type) {
    $event = lifecycleEvent($type, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

    expect(LifecycleEvent::fromJson($event->toJson())->toArray())->toBe($event->toArray())
        ->and($event->known)->toBeTrue();
})->with(LifecycleEventType::cases());

it('ignores a duplicate event id', function () {
    $log = new LifecycleLog;
    $event = lifecycleEvent(LifecycleEventType::Created, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null);

    expect($log->record($event))->toBeTrue()
        ->and($log->record($event))->toBeFalse()
        ->and($log->events())->toHaveCount(1);
});

it('keeps unknown newer event types inspectable without changing display status', function () {
    $log = new LifecycleLog;
    $log->record(lifecycleEvent(LifecycleEventType::Created, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null));

    $unknown = LifecycleEvent::fromArray([
        'schema' => 'molly.lifecycle_event.v2',
        'event_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'type' => 'orb_heartbeat',
        'occurred_at' => '2026-09-18T12:01:00Z',
        'task_id' => ContractFixtures::TASK_ID,
        'run_id' => ContractFixtures::RUN_ID,
        'payload' => ['note' => 'Future Orb heartbeat.'],
    ]);

    expect($unknown->known)->toBeFalse()
        ->and($log->record($unknown))->toBeTrue()
        ->and($log->events()[1]->type)->toBe('orb_heartbeat')
        ->and($log->displayStatus(ContractFixtures::TASK_ID))->toBe(DisplayStatus::Pending);
});

it('derives display status from known events instead of a stored status field', function () {
    $log = new LifecycleLog;
    $log->record(lifecycleEvent(LifecycleEventType::Created, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null));
    $log->record(lifecycleEvent(LifecycleEventType::TestLocked, '99999999-9999-4999-8999-999999999999'));
    expect($log->displayStatus(ContractFixtures::TASK_ID))->toBe(DisplayStatus::Pending);

    $log->record(lifecycleEvent(LifecycleEventType::WorkspacePrepared, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'));
    $log->record(lifecycleEvent(LifecycleEventType::AgentStarted, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'));
    $log->record(lifecycleEvent(LifecycleEventType::ApprovalRequested, 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'));
    expect($log->displayStatus(ContractFixtures::TASK_ID))->toBe(DisplayStatus::AwaitingApproval);

    $log->record(lifecycleEvent(LifecycleEventType::ApprovalResolved, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'));
    expect($log->displayStatus(ContractFixtures::TASK_ID))->toBe(DisplayStatus::Approved);

    $log->record(lifecycleEvent(LifecycleEventType::PullRequestOpened, '11111111-1111-4111-8111-111111111111'));
    expect($log->displayStatus(ContractFixtures::TASK_ID))->toBe(DisplayStatus::Approved)
        ->and($log->latestOf(ContractFixtures::TASK_ID, LifecycleEventType::PullRequestOpened)?->type())->toBe(LifecycleEventType::PullRequestOpened);

    $log->record(lifecycleEvent(LifecycleEventType::Merged, 'ffffffff-ffff-4fff-8fff-ffffffffffff'));
    expect($log->displayStatus(ContractFixtures::TASK_ID))->toBe(DisplayStatus::Merged);
});

it('does not redispatch after an accepted run, and waits after an unconfirmed dispatch', function () {
    $log = new LifecycleLog;
    $log->record(lifecycleEvent(LifecycleEventType::DispatchRequested, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));

    expect($log->dispatchDecision(ContractFixtures::TASK_ID, ContractFixtures::RUN_ID))->toBe(DispatchDecision::Unconfirmed);

    $log->record(lifecycleEvent(LifecycleEventType::AgentStarted, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'));

    expect($log->dispatchDecision(ContractFixtures::TASK_ID, ContractFixtures::RUN_ID))->toBe(DispatchDecision::AlreadyAccepted)
        ->and($log->dispatchDecision(ContractFixtures::TASK_ID, '99999999-9999-4999-8999-999999999999'))->toBe(DispatchDecision::Needed);
});

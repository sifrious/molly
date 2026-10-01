<?php

namespace Sifrious\Molly\Contracts;

enum LifecycleEventType: string
{
    case Created = 'created';
    case WorkspacePrepared = 'workspace_prepared';
    case DispatchRequested = 'dispatch_requested';
    case AgentStarted = 'agent_started';
    case ProposalReceived = 'proposal_received';
    case EditsAccepted = 'edits_accepted';
    case EditsRejected = 'edits_rejected';
    case VerificationStarted = 'verification_started';
    case VerificationFinished = 'verification_finished';
    case RetryScheduled = 'retry_scheduled';
    case ApprovalRequested = 'approval_requested';
    case TestLocked = 'test_locked';
    case ApprovalResolved = 'approval_resolved';
    case PullRequestOpened = 'pull_request_opened';
    case Merged = 'merged';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Recovered = 'recovered';
    case HandedOff = 'handed_off';
    case StartRefused = 'start_refused';
    case CommandRequested = 'command.requested';
    case CommandStarted = 'command.started';
    case CommandCompleted = 'command.completed';
    case CommandFailed = 'command.failed';
    case CheckRecorded = 'check.recorded';
    case ArtifactRecorded = 'artifact.recorded';
    case RecommendationRecorded = 'recommendation.recorded';
    case HandoffRequested = 'handoff.requested';
    case HandoffAcknowledged = 'handoff.acknowledged';
}

import Foundation

/// Records human approval onto the worktree lifecycle log.
///
/// Bloom does not open a pull request here. It writes the same `approval_resolved` event
/// Molly's `molly:approve` command writes, so inspect, journals, and the pull request strip
/// all read one history.
public enum MollyApproval {
    /// - Returns: the snapshot after the event is recorded.
    public static func record(
        _ snapshot: MollyTaskSnapshot,
        in worktree: String,
        at time: Date = Date(),
        eventID: String = newID()
    ) throws -> MollyTaskSnapshot {
        if snapshot.status == .approved { return snapshot }
        guard snapshot.status == .awaitingApproval else {
            throw MollyContractError(
                "APPROVAL_NOT_REQUESTED: Approve a task only after required checks pass and Molly asks for human approval."
            )
        }
        if !snapshot.requiredBlockers.isEmpty {
            throw MollyContractError(
                "APPROVAL_BLOCKED: Required checks have not passed."
            )
        }

        let event = MollyLifecycleEvent(
            eventID: try MollyJSON.uuid(eventID, named: "event_id"),
            type: MollyLifecycleEventType.approvalResolved.rawValue,
            occurredAt: time,
            taskID: snapshot.contract.taskID,
            runID: snapshot.latestRunID,
            payload: [
                "before_pull_request": .bool(true),
                "before_merge": .bool(true),
                "pull_request_opened": .bool(false),
            ],
            known: true,
            schema: MollyLifecycleEvent.schema
        )
        try MollyWorktreeFiles.append(event, in: worktree)
        return MollyTaskSnapshot.make(
            contract: snapshot.contract,
            log: MollyWorktreeFiles.loadLog(in: worktree),
            outcomes: snapshot.outcomes,
            issueURL: snapshot.issueURL
        )
    }
}

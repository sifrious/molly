import Foundation

/// One Molly lifecycle event from `.molly/lifecycle.jsonl`.
///
/// Duplicate `event_id` values are ignored by `MollyLifecycleLog`. An unknown newer type stays
/// inspectable and does not move display status. That is the same rule Molly's PHP log uses.
public struct MollyLifecycleEvent: Sendable, Hashable {
    public static let schema = "molly.lifecycle_event.v1"

    public var eventID: String
    public var type: String
    public var occurredAt: Date
    public var taskID: String
    public var runID: String?
    public var payload: [String: JSONValue]
    public var known: Bool
    public var schema: String

    public var knownType: MollyLifecycleEventType? {
        known ? MollyLifecycleEventType(rawValue: type) : nil
    }

    public static func decode(_ json: JSONValue) throws -> MollyLifecycleEvent {
        guard let schema = json["schema"]?.stringValue, !schema.isEmpty else {
            throw MollyContractError("CONTRACT_SCHEMA_INVALID: A lifecycle event needs a schema.")
        }
        let type = try MollyJSON.string(json, "type")
        let knownSchema = schema == Self.schema
        let knownType = MollyLifecycleEventType(rawValue: type) != nil
        return MollyLifecycleEvent(
            eventID: try MollyJSON.uuid(json, "event_id"),
            type: type,
            occurredAt: try MollyJSON.time(json, "occurred_at"),
            taskID: try MollyJSON.uuid(json, "task_id"),
            runID: try MollyJSON.optionalUuid(json, "run_id"),
            payload: try MollyJSON.object(json["payload"] ?? .object([:]), named: "payload"),
            known: knownSchema && knownType,
            schema: schema
        )
    }

    public static func decode(line: String) throws -> MollyLifecycleEvent {
        guard let json = JSONValue.parse(line) else {
            throw MollyContractError("CONTRACT_JSON_INVALID: A contract document must be a JSON object.")
        }
        return try decode(json)
    }

    public var json: JSONValue {
        var object: [String: JSONValue] = [
            "schema": .string(schema),
            "event_id": .string(eventID),
            "type": .string(type),
            "occurred_at": .string(MollyJSON.formatTime(occurredAt)),
            "task_id": .string(taskID),
            "payload": .object(payload),
            "known": .bool(known),
        ]
        if let runID {
            object["run_id"] = .string(runID)
        } else {
            object["run_id"] = .null
        }
        return .object(object)
    }

    public var jsonLine: String {
        (try? MollyJSON.encode(json)) ?? "{}"
    }
}

public enum MollyLifecycleEventType: String, Sendable, Hashable, CaseIterable {
    case created
    case workspacePrepared = "workspace_prepared"
    case dispatchRequested = "dispatch_requested"
    case agentStarted = "agent_started"
    case proposalReceived = "proposal_received"
    case editsAccepted = "edits_accepted"
    case editsRejected = "edits_rejected"
    case verificationStarted = "verification_started"
    case verificationFinished = "verification_finished"
    case retryScheduled = "retry_scheduled"
    case approvalRequested = "approval_requested"
    case testLocked = "test_locked"
    case approvalResolved = "approval_resolved"
    case pullRequestOpened = "pull_request_opened"
    case merged
    case stopped
    case failed
    case recovered
    case handedOff = "handed_off"
}

/// Display status derived from known events. Last known event wins.
///
/// Opening a pull request does not leave Approved. That matches Molly: the human already
/// approved, and the recorded GitHub URL is evidence rather than a new state.
public enum MollyDisplayStatus: String, Sendable, Hashable, CaseIterable {
    case pending
    case preparing
    case running
    case awaitingApproval = "awaiting_approval"
    case approved
    case failed
    case stopped
    case handedOff = "handed_off"
    case merged

    public var headline: String {
        switch self {
        case .pending: "Pending"
        case .preparing: "Preparing workspace"
        case .running: "Running"
        case .awaitingApproval: "Waiting for approval"
        case .approved: "Approved"
        case .failed: "Failed"
        case .stopped: "Stopped"
        case .handedOff: "Handed off"
        case .merged: "Merged"
        }
    }
}

public struct MollyLifecycleLog: Sendable, Hashable {
    private var eventsByID: [String: MollyLifecycleEvent] = [:]
    private var order: [String] = []

    public init() {}

    @discardableResult
    public mutating func record(_ event: MollyLifecycleEvent) -> Bool {
        if eventsByID[event.eventID] != nil { return false }
        eventsByID[event.eventID] = event
        order.append(event.eventID)
        return true
    }

    public func events(taskID: String? = nil) -> [MollyLifecycleEvent] {
        order.compactMap { id in
            guard let event = eventsByID[id] else { return nil }
            if let taskID, event.taskID != taskID { return nil }
            return event
        }
    }

    public func displayStatus(taskID: String) -> MollyDisplayStatus {
        var status = MollyDisplayStatus.pending
        for event in events(taskID: taskID) {
            guard let type = event.knownType else { continue }
            status = Self.status(after: type)
        }
        return status
    }

    public func latest(_ type: MollyLifecycleEventType, taskID: String) -> MollyLifecycleEvent? {
        events(taskID: taskID).last { $0.knownType == type }
    }

    /// Loads `.molly/lifecycle.jsonl` without throwing on a corrupt line.
    public static func load(text: String) -> MollyLifecycleLog {
        var log = MollyLifecycleLog()
        for line in text.split(whereSeparator: \.isNewline) {
            let trimmed = line.trimmingCharacters(in: .whitespaces)
            if trimmed.isEmpty { continue }
            guard let event = try? MollyLifecycleEvent.decode(line: String(trimmed)) else { continue }
            log.record(event)
        }
        return log
    }

    private static func status(after type: MollyLifecycleEventType) -> MollyDisplayStatus {
        switch type {
        case .created, .testLocked: .pending
        case .workspacePrepared: .preparing
        case .dispatchRequested, .agentStarted, .proposalReceived, .editsAccepted, .editsRejected,
             .verificationStarted, .verificationFinished, .retryScheduled, .recovered:
            .running
        case .approvalRequested: .awaitingApproval
        case .approvalResolved, .pullRequestOpened: .approved
        case .handedOff: .handedOff
        case .stopped: .stopped
        case .failed: .failed
        case .merged: .merged
        }
    }
}

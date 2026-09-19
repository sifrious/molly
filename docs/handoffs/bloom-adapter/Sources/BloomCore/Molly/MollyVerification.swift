import Foundation

/// One Molly verifier receipt.
///
/// Pest, protected-test integrity, and sandbox integrity remain required. Clever and TypeSafe
/// never override a failed required gate. Bloom only displays these; it does not recompute them.
public struct MollyVerificationOutcome: Sendable, Hashable {
    public static let schema = "molly.verification_outcome.v1"

    public var verifier: String
    public var state: String
    public var policy: String
    public var failureAction: String
    public var diagnosticsRef: String?
    public var evidenceDigest: String
    public var startedAt: Date
    public var finishedAt: Date
    public var anotherAttemptPermitted: Bool

    public var isRequired: Bool { policy == "required" }
    public var passed: Bool { state == "PASS" }
    public var failed: Bool { state == "FAIL" }

    public static func decode(_ json: JSONValue) throws -> MollyVerificationOutcome {
        try MollyJSON.requireSchema(json, schema)
        let verifier = try MollyJSON.string(json, "verifier")
        let state = try MollyJSON.string(json, "state")
        let policy = try MollyJSON.string(json, "policy")
        let action = try MollyJSON.string(json, "failure_action")
        guard ["PASS", "FAIL", "REVIEW_REQUIRED", "NOT_RUN"].contains(state),
              ["required", "advisory"].contains(policy),
              ["retry", "fail", "warn"].contains(action)
        else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: state, policy, and failure_action must be known values.")
        }
        if policy == "required", action == "warn" {
            throw MollyContractError("CONTRACT_FIELD_INVALID: A required verifier cannot use the warn failure action.")
        }
        let another = try MollyJSON.boolean(json, "another_attempt_permitted")
        if state == "PASS", another {
            throw MollyContractError("CONTRACT_FIELD_INVALID: A passing outcome does not permit another attempt.")
        }
        let started = try MollyJSON.time(json, "started_at")
        let finished = try MollyJSON.time(json, "finished_at")
        if finished < started {
            throw MollyContractError("CONTRACT_FIELD_INVALID: finished_at cannot precede started_at.")
        }
        return MollyVerificationOutcome(
            verifier: verifier,
            state: state,
            policy: policy,
            failureAction: action,
            diagnosticsRef: try MollyJSON.optionalString(json, "diagnostics_ref"),
            evidenceDigest: try MollyJSON.sha256(
                try MollyJSON.string(json, "evidence_digest"),
                named: "evidence_digest"
            ),
            startedAt: started,
            finishedAt: finished,
            anotherAttemptPermitted: another
        )
    }

    public static func decode(text: String) throws -> MollyVerificationOutcome {
        guard let json = JSONValue.parse(text) else {
            throw MollyContractError("CONTRACT_JSON_INVALID: A contract document must be a JSON object.")
        }
        return try decode(json)
    }
}

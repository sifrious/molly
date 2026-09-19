import Foundation
@testable import BloomCore

/// JSON produced by Molly's PHP `TaskContract` fixture, byte for byte.
enum MollyContractFixtures {
    static let taskID = "11111111-1111-4111-8111-111111111111"
    static let runID = "22222222-2222-4222-8222-222222222222"
    static let workspaceID = "44444444-4444-4444-8444-444444444444"
    static let testID = "77777777-7777-4777-8777-777777777777"
    static let sha = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    static let digest = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
    static let workspacePath = "/tmp/bloom/molly-workspace"
    static let branch = "bloom/hello"

    static let contractJSON = """
        {"schema":"molly.task_contract.v1","task_id":"\(taskID)","request":"Return Hello from the greeting helper.","repository":{"provider":"github","owner":"sifrious","name":"molly","url":"https://github.com/sifrious/molly"},"bloom_workspace_id":"\(workspaceID)","workspace_path":"\(workspacePath)","branch":"\(branch)","base_sha":"\(sha)","allowed_write_paths":["app/Greeting.php"],"protected_paths":["tests/Feature/GreetingTest.php"],"acceptance_tests":[{"id":"\(testID)","path":"tests/Feature/GreetingTest.php","digest":"\(digest)"}],"attempt_budget":3,"verifier_policies":{"pest":{"policy":"required","failure_action":"retry"},"protected_test_integrity":{"policy":"required","failure_action":"fail"},"allowed_file_integrity":{"policy":"required","failure_action":"fail"},"sandbox_integrity":{"policy":"required","failure_action":"fail"},"parallel_join":{"policy":"required","failure_action":"retry"},"tarpit":{"policy":"required","failure_action":"retry"},"clever":{"policy":"advisory","failure_action":"warn"},"typesafe":{"policy":"advisory","failure_action":"warn"}},"execution_target":{"kind":"local","target_id":null,"reason":"Local execution is the default."},"approval":{"before_pull_request":true,"before_merge":true,"before_remote_dispatch":true}}
        """

    static let pestFailJSON = """
        {"schema":"molly.verification_outcome.v1","verifier":"pest","state":"FAIL","policy":"required","failure_action":"retry","diagnostics_ref":"storage/molly/pest.json","evidence_digest":"\(digest)","started_at":"2026-09-18T12:00:00Z","finished_at":"2026-09-18T12:00:12Z","another_attempt_permitted":true}
        """

    static let pestPassJSON = """
        {"schema":"molly.verification_outcome.v1","verifier":"pest","state":"PASS","policy":"required","failure_action":"retry","diagnostics_ref":"storage/molly/pest.json","evidence_digest":"\(digest)","started_at":"2026-09-18T12:00:00Z","finished_at":"2026-09-18T12:00:12Z","another_attempt_permitted":false}
        """

    static func contract() throws -> MollyTaskContract {
        try MollyTaskContract.decode(text: contractJSON)
    }

    static func workspace(path: String = workspacePath) -> Workspace {
        Workspace(
            id: WorkspaceID(workspaceID),
            repoID: RepoID("repo"),
            name: "hello",
            branch: branch,
            path: path,
            baseBranch: "main"
        )
    }

    static func event(
        _ type: MollyLifecycleEventType,
        eventID: String,
        runID: String? = Self.runID,
        payload: [String: JSONValue] = [:]
    ) throws -> MollyLifecycleEvent {
        MollyLifecycleEvent(
            eventID: eventID,
            type: type.rawValue,
            occurredAt: try MollyJSON.parseTime("2026-09-18T12:00:00Z", named: "occurred_at"),
            taskID: taskID,
            runID: runID,
            payload: payload,
            known: true,
            schema: MollyLifecycleEvent.schema
        )
    }

    static func snapshot(
        statusEvents: [MollyLifecycleEventType] = [.created, .approvalRequested],
        outcomes: [MollyVerificationOutcome] = []
    ) throws -> MollyTaskSnapshot {
        var log = MollyLifecycleLog()
        let ids = [
            "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
            "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
            "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
            "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
            "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee",
        ]
        for (index, type) in statusEvents.enumerated() {
            log.record(try event(type, eventID: ids[index], runID: type == .created ? nil : runID))
        }
        return MollyTaskSnapshot.make(contract: try contract(), log: log, outcomes: outcomes)
    }
}

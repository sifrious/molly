import Foundation
import Testing
@testable import BloomCore

/// Bloom reads Molly's versioned JSON and binds it to the selected workspace.
///
/// Nothing here starts an agent, opens GitHub, or creates a worktree. The decisions the
/// inspector and the pull request strip have to agree on live here so a window is not required.
@Suite("Molly's Bloom adapter")
struct MollyAdapterTests {
    @Test("round-trips Molly's PHP task contract")
    func roundTripContract() throws {
        let contract = try MollyContractFixtures.contract()
        #expect(contract.taskID == MollyContractFixtures.taskID)
        #expect(contract.bloomWorkspaceID == WorkspaceID(MollyContractFixtures.workspaceID))
        #expect(contract.workspacePath == MollyContractFixtures.workspacePath)
        #expect(contract.branch == MollyContractFixtures.branch)
        #expect(contract.allowedWritePaths == ["app/Greeting.php"])
        #expect(contract.protectedPaths == ["tests/Feature/GreetingTest.php"])
        #expect(contract.acceptanceTests[0].path == "tests/Feature/GreetingTest.php")
        #expect(contract.approval.beforePullRequest)
        #expect(contract.approval.beforeMerge)
        #expect(contract.executionTarget.kind == "local")
        #expect(contract.verifierPolicies["pest"]?.policy == "required")
        #expect(contract.verifierPolicies["clever"]?.failureAction == "warn")
    }

    @Test("rejects an acceptance test that is also writable")
    func rejectsWritableTest() throws {
        var json = try #require(JSONValue.parse(MollyContractFixtures.contractJSON)?.objectValue)
        json["allowed_write_paths"] = .array([
            .string("app/Greeting.php"),
            .string("tests/Feature/GreetingTest.php"),
        ])
        #expect(throws: MollyContractError.self) {
            try MollyTaskContract.decode(.object(json))
        }
    }

    @Test("rejects an Orb request without a target id")
    func rejectsUnnamedOrb() throws {
        var json = try #require(JSONValue.parse(MollyContractFixtures.contractJSON)?.objectValue)
        json["execution_target"] = .object([
            "kind": .string("orb"),
            "target_id": .null,
            "reason": .string("Use a remote Orb."),
        ])
        #expect(throws: MollyContractError.self) {
            try MollyTaskContract.decode(.object(json))
        }
    }

    @Test("binds the contract to the selected Bloom workspace")
    func bindsExistingWorkspace() throws {
        let contract = try MollyContractFixtures.contract()
        let binding = try MollyWorkspaceBinding.bind(
            contract: contract,
            workspace: MollyContractFixtures.workspace(),
            baseSHA: MollyContractFixtures.sha
        )
        #expect(binding.workspaceID.rawValue == MollyContractFixtures.workspaceID)
        #expect(binding.branch == MollyContractFixtures.branch)
    }

    @Test("refuses a second worktree")
    func refusesSecondWorktree() throws {
        let contract = try MollyContractFixtures.contract()
        #expect(throws: MollyContractError.self) {
            try MollyWorkspaceBinding.bind(
                contract: contract,
                workspace: MollyContractFixtures.workspace(),
                baseSHA: MollyContractFixtures.sha,
                createdWorktree: true
            )
        }
    }

    @Test("refuses a contract for a different Bloom workspace")
    func refusesOtherWorkspace() throws {
        let contract = try MollyContractFixtures.contract()
        let other = Workspace(
            id: WorkspaceID.new(),
            repoID: RepoID("repo"),
            name: "hello",
            branch: MollyContractFixtures.branch,
            path: MollyContractFixtures.workspacePath,
            baseBranch: "main"
        )
        #expect(throws: MollyContractError.self) {
            try MollyWorkspaceBinding.bind(
                contract: contract, workspace: other, baseSHA: MollyContractFixtures.sha
            )
        }
    }

    @Test("refuses a contract for a different branch")
    func refusesOtherBranch() throws {
        let contract = try MollyContractFixtures.contract()
        let other = Workspace(
            id: WorkspaceID(MollyContractFixtures.workspaceID),
            repoID: RepoID("repo"),
            name: "hello",
            branch: "other",
            path: MollyContractFixtures.workspacePath,
            baseBranch: "main"
        )
        #expect(throws: MollyContractError.self) {
            try MollyWorkspaceBinding.bind(
                contract: contract, workspace: other, baseSHA: MollyContractFixtures.sha
            )
        }
    }

    @Test("refuses a remote Orb from this adapter")
    func refusesOrb() throws {
        var json = try #require(JSONValue.parse(MollyContractFixtures.contractJSON)?.objectValue)
        json["execution_target"] = .object([
            "kind": .string("orb"),
            "target_id": .string("orb-1"),
            "reason": .string("Use a remote Orb."),
        ])
        let contract = try MollyTaskContract.decode(.object(json))
        #expect(throws: MollyContractError.self) {
            try MollyWorkspaceBinding.bind(
                contract: contract,
                workspace: MollyContractFixtures.workspace(),
                baseSHA: MollyContractFixtures.sha
            )
        }
    }
}

@Suite("Molly lifecycle display status")
struct MollyLifecycleTests {
    @Test("ignores a duplicate event id")
    func ignoresDuplicates() throws {
        var log = MollyLifecycleLog()
        let event = try MollyContractFixtures.event(
            .created, eventID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", runID: nil
        )
        #expect(log.record(event))
        #expect(!log.record(event))
        #expect(log.events().count == 1)
    }

    @Test("keeps unknown newer event types inspectable without changing display status")
    func unknownEventsStayInspectable() throws {
        var log = MollyLifecycleLog()
        log.record(try MollyContractFixtures.event(
            .created, eventID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", runID: nil
        ))
        let unknown = try MollyLifecycleEvent.decode(line: """
            {"schema":"molly.lifecycle_event.v2","event_id":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb","type":"orb_heartbeat","occurred_at":"2026-09-18T12:01:00Z","task_id":"\(MollyContractFixtures.taskID)","run_id":"\(MollyContractFixtures.runID)","payload":{"note":"Future Orb heartbeat."}}
            """)
        #expect(!unknown.known)
        #expect(log.record(unknown))
        #expect(log.events()[1].type == "orb_heartbeat")
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .pending)
    }

    @Test("derives display status from known events")
    func displayStatus() throws {
        var log = MollyLifecycleLog()
        log.record(try MollyContractFixtures.event(.created, eventID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", runID: nil))
        log.record(try MollyContractFixtures.event(.workspacePrepared, eventID: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"))
        log.record(try MollyContractFixtures.event(.agentStarted, eventID: "cccccccc-cccc-4ccc-8ccc-cccccccccccc"))
        log.record(try MollyContractFixtures.event(.testLocked, eventID: "99999999-9999-4999-8999-999999999999"))
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .pending)
        log.record(try MollyContractFixtures.event(.approvalRequested, eventID: "dddddddd-dddd-4ddd-8ddd-dddddddddddd"))
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .awaitingApproval)

        log.record(try MollyContractFixtures.event(.approvalResolved, eventID: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"))
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .approved)

        log.record(try MollyContractFixtures.event(.pullRequestOpened, eventID: "11111111-1111-4111-8111-111111111111"))
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .approved)

        log.record(try MollyContractFixtures.event(.merged, eventID: "ffffffff-ffff-4fff-8fff-ffffffffffff"))
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .merged)
    }

    @Test("skips a corrupt lifecycle line")
    func skipsCorruptLine() {
        let log = MollyLifecycleLog.load(text: """
            not-json
            {"schema":"molly.lifecycle_event.v1","event_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","type":"created","occurred_at":"2026-09-18T12:00:00Z","task_id":"\(MollyContractFixtures.taskID)","run_id":null,"payload":[],"known":true}
            """)
        #expect(log.events().count == 1)
        #expect(log.displayStatus(taskID: MollyContractFixtures.taskID) == .pending)
    }
}

@Suite("Molly pull request approval")
struct MollyPullRequestGateTests {
    @Test("Create pull request waits for Molly approval")
    func createWaits() throws {
        let approval = try MollyContractFixtures.contract().approval
        let gate = MollyPullRequestGate.decide(
            status: .awaitingApproval, approval: approval, hasPullRequest: false
        )
        #expect(!gate.canOpenPullRequest)
        #expect(!gate.canMerge)
        #expect(gate.note == "Molly needs approval first.")

        let busy = BranchActionAvailability.allowed
        let combined = gate.combined(with: busy, hasPullRequest: false)
        #expect(!combined.isAllowed)
        #expect(combined.note == gate.note)
    }

    @Test("an approved task may ask Bloom to open a pull request")
    func approvedMayOpen() throws {
        let approval = try MollyContractFixtures.contract().approval
        let gate = MollyPullRequestGate.decide(
            status: .approved, approval: approval, hasPullRequest: false
        )
        #expect(gate.canOpenPullRequest)
        #expect(!gate.canMerge)
        #expect(gate.combined(with: .allowed, hasPullRequest: false) == .allowed)
    }

    @Test("merge still waits if approval has not been recorded")
    func mergeWaits() throws {
        let approval = try MollyContractFixtures.contract().approval
        let gate = MollyPullRequestGate.decide(
            status: .awaitingApproval, approval: approval, hasPullRequest: true
        )
        #expect(!gate.canMerge)
        #expect(!gate.combined(with: .allowed, hasPullRequest: true).isAllowed)
    }

    @Test("a running agent still blocks Continue after Molly has approved")
    func busyAgentStillBlocks() throws {
        let approval = try MollyContractFixtures.contract().approval
        let gate = MollyPullRequestGate.decide(
            status: .approved, approval: approval, hasPullRequest: true
        )
        let busy = BranchActionAvailability(
            isAllowed: false,
            note: "The agent is still running here.",
            reason: "Wait."
        )
        #expect(gate.combined(with: busy, hasPullRequest: true) == busy)
    }

    @Test("Pest failure cannot be treated as approval")
    func pestFailureBlocksApproval() throws {
        let pest = try MollyVerificationOutcome.decode(text: MollyContractFixtures.pestFailJSON)
        let snapshot = try MollyContractFixtures.snapshot(
            statusEvents: [.created, .approvalRequested],
            outcomes: [pest]
        )
        #expect(snapshot.status == .awaitingApproval)
        #expect(!snapshot.pestPassed)
        #expect(throws: MollyContractError.self) {
            try MollyApproval.record(snapshot, in: "/tmp/does-not-matter")
        }
    }
}

@Suite("Molly worktree files", .scratchDirectory)
struct MollyWorktreeFileTests {
    @Test("imports a contract into the existing worktree and records approval")
    func importAndApprove() throws {
        let worktree = TestScratch.unique("molly-workspace")
        try FileManager.default.createDirectory(atPath: worktree, withIntermediateDirectories: true)
        let workspace = Workspace(
            id: WorkspaceID(MollyContractFixtures.workspaceID),
            repoID: RepoID("repo"),
            name: "hello",
            branch: MollyContractFixtures.branch,
            path: worktree,
            baseBranch: "main"
        )
        var json = try #require(JSONValue.parse(MollyContractFixtures.contractJSON)?.objectValue)
        json["workspace_path"] = .string(worktree)
        let text = try MollyJSON.encode(.object(json))

        let imported = try MollyWorktreeFiles.importContract(
            json: text, workspace: workspace, baseSHA: MollyContractFixtures.sha
        )
        #expect(imported.contract.taskID == MollyContractFixtures.taskID)
        #expect(imported.status == .pending)
        #expect(FileManager.default.fileExists(atPath: MollyWorktreeFiles.contract(in: worktree)))

        let created = try MollyContractFixtures.event(
            .created, eventID: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", runID: nil
        )
        let requested = try MollyContractFixtures.event(
            .approvalRequested, eventID: "dddddddd-dddd-4ddd-8ddd-dddddddddddd"
        )
        try MollyWorktreeFiles.append(created, in: worktree)
        try MollyWorktreeFiles.append(requested, in: worktree)
        let waiting = try #require(MollyWorktreeFiles.loadSnapshot(workspace: workspace))
        #expect(waiting.status == .awaitingApproval)

        let approved = try MollyApproval.record(
            waiting,
            in: worktree,
            at: try MollyJSON.parseTime("2026-09-18T12:02:00Z", named: "occurred_at"),
            eventID: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"
        )
        #expect(approved.status == .approved)
        #expect(try MollyApproval.record(approved, in: worktree).status == .approved)

        let gate = MollyPullRequestGate.decide(
            status: approved.status,
            approval: approved.contract.approval,
            hasPullRequest: false
        )
        #expect(gate.canOpenPullRequest)
        #expect(!gate.canMerge)
    }

    @Test("a contract for another workspace is not shown here")
    func hidesForeignContract() throws {
        let worktree = TestScratch.unique("molly-other")
        try FileManager.default.createDirectory(atPath: worktree, withIntermediateDirectories: true)
        try MollyContractFixtures.contractJSON.write(
            toFile: {
                try FileManager.default.createDirectory(
                    atPath: MollyWorktreeFiles.directory(in: worktree),
                    withIntermediateDirectories: true
                )
                return MollyWorktreeFiles.contract(in: worktree)
            }(),
            atomically: true,
            encoding: .utf8
        )
        let workspace = Workspace(
            id: WorkspaceID.new(),
            repoID: RepoID("repo"),
            name: "hello",
            branch: MollyContractFixtures.branch,
            path: worktree,
            baseBranch: "main"
        )
        #expect(MollyWorktreeFiles.loadSnapshot(workspace: workspace) == nil)
    }
}

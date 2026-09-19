import Foundation

/// What the inspector shows for a Molly task bound to this workspace.
///
/// Derived from the contract, lifecycle log, and verifier receipts. Bloom does not store a
/// second status field. Failed or stopped attempts stay reviewable in the same worktree.
public struct MollyTaskSnapshot: Sendable, Hashable {
    public var contract: MollyTaskContract
    public var status: MollyDisplayStatus
    public var outcomes: [MollyVerificationOutcome]
    public var issueURL: String?
    public var pullRequestURL: String?
    public var pullRequestNumber: Int?
    public var mergeSHA: String?
    public var latestRunID: String?

    public var protectedTest: MollyAcceptanceTest? { contract.acceptanceTests.first }

    public var requiredBlockers: [MollyVerificationOutcome] {
        outcomes.filter { $0.isRequired && !$0.passed }
    }

    public var pestPassed: Bool {
        outcomes.contains { $0.verifier == "pest" && $0.passed }
    }

    public static func make(
        contract: MollyTaskContract,
        log: MollyLifecycleLog,
        outcomes: [MollyVerificationOutcome] = [],
        issueURL: String? = nil
    ) -> MollyTaskSnapshot {
        let opened = log.latest(.pullRequestOpened, taskID: contract.taskID)
        let merged = log.latest(.merged, taskID: contract.taskID)
        let runID = log.events(taskID: contract.taskID).reversed().compactMap(\.runID).first
        return MollyTaskSnapshot(
            contract: contract,
            status: log.displayStatus(taskID: contract.taskID),
            outcomes: outcomes,
            issueURL: Self.httpsGitHubURL(issueURL),
            pullRequestURL: Self.httpsGitHubURL(opened?.payload["url"]?.stringValue),
            pullRequestNumber: opened?.payload["number"]?.intValue,
            mergeSHA: merged?.payload["sha"]?.stringValue.flatMap { try? MollyJSON.sha1($0, named: "sha") },
            latestRunID: runID
        )
    }

    public var summary: String {
        if let test = protectedTest {
            return "\(status.headline). Protected test \(test.path)."
        }
        return status.headline
    }

    private static func httpsGitHubURL(_ value: String?) -> String? {
        guard let value else { return nil }
        guard value.wholeMatch(of: githubURL) != nil else { return nil }
        return value
    }

    private static let githubURL = /https:\/\/github\.com\/[A-Za-z0-9][A-Za-z0-9-]*\/[A-Za-z0-9_.-]+\/(?:issues|pull)\/[1-9][0-9]*/
}

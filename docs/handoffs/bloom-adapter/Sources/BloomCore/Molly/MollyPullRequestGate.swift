import Foundation

/// Whether Bloom may ask its agent to open or merge a pull request for a Molly task.
///
/// Bloom still opens and merges through the existing strip: the agent composes the GitHub
/// turn. This gate only refuses that request until Molly has recorded human approval. Merge
/// stays a second, explicit human action after the pull request exists.
public struct MollyPullRequestGate: Sendable, Hashable {
    public var canOpenPullRequest: Bool
    public var canMerge: Bool
    public var note: String?
    public var reason: String?

    public static let allowed = MollyPullRequestGate(
        canOpenPullRequest: true, canMerge: true, note: nil, reason: nil
    )

    public static func decide(
        status: MollyDisplayStatus,
        approval: MollyApprovalRequirements,
        hasPullRequest: Bool
    ) -> MollyPullRequestGate {
        if status == .merged {
            return .allowed
        }

        if approval.beforePullRequest, status != .approved, !hasPullRequest {
            return MollyPullRequestGate(
                canOpenPullRequest: false,
                canMerge: false,
                note: "Molly needs approval first.",
                reason: "Approve the Molly task after required checks pass. Bloom then opens the pull request from this workspace."
            )
        }

        if approval.beforeMerge, status != .approved, status != .merged {
            return MollyPullRequestGate(
                canOpenPullRequest: hasPullRequest || status == .approved,
                canMerge: false,
                note: "Molly needs approval first.",
                reason: "Approve the Molly task before Bloom asks its agent to merge."
            )
        }

        if hasPullRequest {
            return MollyPullRequestGate(
                canOpenPullRequest: false,
                canMerge: true,
                note: nil,
                reason: nil
            )
        }

        return MollyPullRequestGate(
            canOpenPullRequest: true,
            canMerge: false,
            note: nil,
            reason: nil
        )
    }

    /// Combines Molly's approval gate with Bloom's existing busy-agent rule.
    ///
    /// The strip has one availability value. Before a pull request exists, Create is the
    /// action that matters. After one exists, Merge is. Continue and Archive on a merged
    /// pull request stay on Bloom's ordinary busy-agent rule.
    public func combined(
        with branchActions: BranchActionAvailability,
        hasPullRequest: Bool
    ) -> BranchActionAvailability {
        if !branchActions.isAllowed { return branchActions }
        let allowed = hasPullRequest ? canMerge : canOpenPullRequest
        if allowed { return .allowed }
        return BranchActionAvailability(isAllowed: false, note: note, reason: reason)
    }
}

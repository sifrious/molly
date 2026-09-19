import Foundation

/// Whether a Molly contract may run in this Bloom workspace.
///
/// The contract is bound to the selected worktree. A second worktree, a different branch, or a
/// different Bloom workspace id is a hard refusal. Bloom already owns the worktree; Molly must
/// not create another one.
public struct MollyWorkspaceBinding: Sendable, Hashable {
    public var contract: MollyTaskContract
    public var workspaceID: WorkspaceID
    public var workspacePath: String
    public var branch: String
    public var baseSHA: String

    public static func bind(
        contract: MollyTaskContract,
        workspace: Workspace,
        baseSHA: String,
        createdWorktree: Bool = false
    ) throws -> MollyWorkspaceBinding {
        if createdWorktree {
            throw MollyContractError(
                "MOLLY_WORKTREE_REFUSED: Molly runs in the existing Bloom workspace and does not create another worktree."
            )
        }
        guard contract.bloomWorkspaceID == workspace.id else {
            throw MollyContractError(
                "MOLLY_WORKSPACE_MISMATCH: The task contract names a different Bloom workspace."
            )
        }
        guard canonical(contract.workspacePath) == canonical(workspace.path) else {
            throw MollyContractError(
                "MOLLY_WORKSPACE_MISMATCH: The task contract names a different worktree path."
            )
        }
        guard contract.branch == workspace.branch else {
            throw MollyContractError(
                "MOLLY_BRANCH_MISMATCH: The task contract names branch \(contract.branch), not \(workspace.branch)."
            )
        }
        let sha = try MollyJSON.sha1(baseSHA, named: "base_sha")
        guard contract.baseSHA == sha else {
            throw MollyContractError(
                "MOLLY_BASE_MISMATCH: The task contract names a different merge-base SHA."
            )
        }
        if contract.executionTarget.kind != "local" {
            throw MollyContractError(
                "ORB_UNVERIFIED: Bloom's Molly adapter runs only on the selected local workspace."
            )
        }
        return MollyWorkspaceBinding(
            contract: contract,
            workspaceID: workspace.id,
            workspacePath: workspace.path,
            branch: workspace.branch,
            baseSHA: sha
        )
    }

    private static func canonical(_ path: String) -> String {
        URL(filePath: path).standardizedFileURL.resolvingSymlinksInPath().path
    }
}

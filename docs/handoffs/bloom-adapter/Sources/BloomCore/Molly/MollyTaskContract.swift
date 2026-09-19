import Foundation

/// Molly's versioned task contract, decoded from `molly:bloom-contract --json`.
///
/// Bloom never creates a second worktree for this. The contract names an existing workspace,
/// its branch, and the merge-base SHA already on disk. Binding refuses a path, branch, or
/// workspace id that does not match the selected Bloom workspace.
public struct MollyTaskContract: Sendable, Hashable {
    public static let schema = "molly.task_contract.v1"

    public var taskID: String
    public var request: String
    public var repository: MollyRepositoryIdentity
    public var bloomWorkspaceID: WorkspaceID
    public var workspacePath: String
    public var branch: String
    public var baseSHA: String
    public var allowedWritePaths: [String]
    public var protectedPaths: [String]
    public var acceptanceTests: [MollyAcceptanceTest]
    public var attemptBudget: Int
    public var verifierPolicies: [String: MollyVerifierPolicy]
    public var executionTarget: MollyExecutionTarget
    public var approval: MollyApprovalRequirements

    public init(
        taskID: String,
        request: String,
        repository: MollyRepositoryIdentity,
        bloomWorkspaceID: WorkspaceID,
        workspacePath: String,
        branch: String,
        baseSHA: String,
        allowedWritePaths: [String],
        protectedPaths: [String],
        acceptanceTests: [MollyAcceptanceTest],
        attemptBudget: Int,
        verifierPolicies: [String: MollyVerifierPolicy],
        executionTarget: MollyExecutionTarget,
        approval: MollyApprovalRequirements
    ) {
        self.taskID = taskID
        self.request = request
        self.repository = repository
        self.bloomWorkspaceID = bloomWorkspaceID
        self.workspacePath = workspacePath
        self.branch = branch
        self.baseSHA = baseSHA
        self.allowedWritePaths = allowedWritePaths
        self.protectedPaths = protectedPaths
        self.acceptanceTests = acceptanceTests
        self.attemptBudget = attemptBudget
        self.executionTarget = executionTarget
        self.verifierPolicies = verifierPolicies
        self.approval = approval
    }

    public static func decode(_ json: JSONValue) throws -> MollyTaskContract {
        try MollyJSON.requireSchema(json, schema)
        let request = try MollyJSON.string(json, "request")
        if request.count > 8192 {
            throw MollyContractError("CONTRACT_FIELD_INVALID: request must be 1 to 8192 bytes.")
        }
        let workspacePath = try MollyJSON.string(json, "workspace_path")
        let branch = try MollyJSON.string(json, "branch")
        let allowed = try MollyJSON.stringList(json, "allowed_write_paths").map {
            try MollyJSON.relativePath($0, named: "allowed_write_paths")
        }
        let protected = try MollyJSON.stringList(json, "protected_paths").map {
            try MollyJSON.relativePath($0, named: "protected_paths")
        }
        if allowed.isEmpty || allowed.count > 8 {
            throw MollyContractError("CONTRACT_FIELD_INVALID: allowed_write_paths must contain 1 to 8 paths.")
        }
        if Set(allowed).count != allowed.count {
            throw MollyContractError("CONTRACT_FIELD_INVALID: allowed_write_paths cannot contain duplicates.")
        }
        if Set(protected).count != protected.count {
            throw MollyContractError("CONTRACT_FIELD_INVALID: protected_paths cannot contain duplicates.")
        }
        let tests = try MollyJSON.objectList(json, "acceptance_tests").map {
            try MollyAcceptanceTest.decode(.object($0))
        }
        if tests.isEmpty {
            throw MollyContractError("CONTRACT_FIELD_INVALID: At least one acceptance test is required.")
        }
        for test in tests {
            if !protected.contains(test.path) {
                throw MollyContractError("CONTRACT_FIELD_INVALID: Each acceptance test path must also be protected.")
            }
            if allowed.contains(test.path) {
                throw MollyContractError("CONTRACT_FIELD_INVALID: An acceptance test cannot also be writable.")
            }
        }
        if !Set(allowed).isDisjoint(with: Set(protected)) {
            throw MollyContractError("CONTRACT_FIELD_INVALID: Allowed write paths and protected paths cannot overlap.")
        }

        return MollyTaskContract(
            taskID: try MollyJSON.uuid(json, "task_id"),
            request: request,
            repository: try MollyRepositoryIdentity.decode(json["repository"] ?? .null),
            bloomWorkspaceID: WorkspaceID(try MollyJSON.uuid(json, "bloom_workspace_id")),
            workspacePath: workspacePath,
            branch: branch,
            baseSHA: try MollyJSON.sha1(try MollyJSON.string(json, "base_sha"), named: "base_sha"),
            allowedWritePaths: allowed,
            protectedPaths: protected,
            acceptanceTests: tests,
            attemptBudget: try MollyJSON.integer(json, "attempt_budget", min: 1, max: 10),
            verifierPolicies: try MollyVerifierPolicy.decodeMap(json["verifier_policies"] ?? .null),
            executionTarget: try MollyExecutionTarget.decode(json["execution_target"] ?? .null),
            approval: try MollyApprovalRequirements.decode(json["approval"] ?? .null)
        )
    }

    public static func decode(text: String) throws -> MollyTaskContract {
        guard let json = JSONValue.parse(text) else {
            throw MollyContractError("CONTRACT_JSON_INVALID: A contract document must be a JSON object.")
        }
        return try decode(json)
    }
}

public struct MollyRepositoryIdentity: Sendable, Hashable {
    public var provider: String
    public var owner: String
    public var name: String
    public var url: String?

    public static func decode(_ json: JSONValue) throws -> MollyRepositoryIdentity {
        let object = try MollyJSON.object(json, named: "repository")
        let provider = try MollyJSON.string(.object(object), "provider")
        guard provider == "github" || provider == "git" else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: repository.provider must be github or git.")
        }
        let owner = try MollyJSON.string(.object(object), "owner")
        let name = try MollyJSON.string(.object(object), "name")
        return MollyRepositoryIdentity(
            provider: provider,
            owner: owner,
            name: name,
            url: try MollyJSON.optionalString(.object(object), "url")
        )
    }
}

public struct MollyAcceptanceTest: Sendable, Hashable {
    public var id: String
    public var path: String
    public var digest: String

    public static func decode(_ json: JSONValue) throws -> MollyAcceptanceTest {
        let path = try MollyJSON.relativePath(try MollyJSON.string(json, "path"), named: "acceptance_tests.path")
        guard path.hasPrefix("tests/"), path.hasSuffix(".php") else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: acceptance_tests.path must be a PHP file under tests/.")
        }
        return MollyAcceptanceTest(
            id: try MollyJSON.uuid(json, "id"),
            path: path,
            digest: try MollyJSON.sha256(try MollyJSON.string(json, "digest"), named: "acceptance_tests.digest")
        )
    }
}

public struct MollyVerifierPolicy: Sendable, Hashable {
    public var policy: String
    public var failureAction: String

    public static func decodeMap(_ json: JSONValue) throws -> [String: MollyVerifierPolicy] {
        let object = try MollyJSON.object(json, named: "verifier_policies")
        var entries: [String: MollyVerifierPolicy] = [:]
        for (name, value) in object {
            guard !name.isEmpty else {
                throw MollyContractError("CONTRACT_FIELD_INVALID: verifier policy names must be non-empty strings.")
            }
            let entry = try MollyJSON.object(value, named: "verifier_policies.\(name)")
            let policy = try MollyJSON.string(.object(entry), "policy")
            let action = try MollyJSON.string(.object(entry), "failure_action")
            guard ["required", "advisory"].contains(policy), ["retry", "fail", "warn"].contains(action) else {
                throw MollyContractError("CONTRACT_FIELD_INVALID: verifier_policies.\(name) needs a known policy and failure_action.")
            }
            entries[name] = MollyVerifierPolicy(policy: policy, failureAction: action)
        }
        return entries
    }
}

public struct MollyExecutionTarget: Sendable, Hashable {
    public var kind: String
    public var targetID: String?
    public var reason: String?

    public static func decode(_ json: JSONValue) throws -> MollyExecutionTarget {
        let object = try MollyJSON.object(json, named: "execution_target")
        let kind = try MollyJSON.string(.object(object), "kind")
        guard kind == "local" || kind == "orb" else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: execution_target.kind must be local or orb.")
        }
        let targetID = try MollyJSON.optionalString(.object(object), "target_id")
        if kind == "orb", targetID == nil {
            throw MollyContractError("CONTRACT_FIELD_INVALID: An Orb execution target needs a target_id.")
        }
        if kind == "local", targetID != nil {
            throw MollyContractError("CONTRACT_FIELD_INVALID: A local execution target cannot name a remote target_id.")
        }
        return MollyExecutionTarget(
            kind: kind,
            targetID: targetID,
            reason: try MollyJSON.optionalString(.object(object), "reason")
        )
    }
}

public struct MollyApprovalRequirements: Sendable, Hashable {
    public var beforePullRequest: Bool
    public var beforeMerge: Bool
    public var beforeRemoteDispatch: Bool

    public static func decode(_ json: JSONValue) throws -> MollyApprovalRequirements {
        let object = try MollyJSON.object(json, named: "approval")
        return MollyApprovalRequirements(
            beforePullRequest: try MollyJSON.boolean(.object(object), "before_pull_request"),
            beforeMerge: try MollyJSON.boolean(.object(object), "before_merge"),
            beforeRemoteDispatch: try MollyJSON.boolean(.object(object), "before_remote_dispatch")
        )
    }
}

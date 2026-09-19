import Foundation

/// Files Molly writes under `.molly/` in the Bloom worktree.
///
/// Bloom reads these in place. It does not copy them into its database, and it does not
/// create a second checkout to inspect them.
public enum MollyWorktreeFiles {
    public static let directoryName = ".molly"
    public static let lifecycleName = "lifecycle.jsonl"
    public static let contractName = "bloom-contract.json"
    public static let receiptsDirectoryName = "receipts"

    public static func directory(in worktree: String) -> String {
        (worktree as NSString).appendingPathComponent(directoryName)
    }

    public static func lifecycle(in worktree: String) -> String {
        (directory(in: worktree) as NSString).appendingPathComponent(lifecycleName)
    }

    public static func contract(in worktree: String) -> String {
        (directory(in: worktree) as NSString).appendingPathComponent(contractName)
    }

    public static func receipts(in worktree: String, runID: String) -> String {
        ((directory(in: worktree) as NSString)
            .appendingPathComponent(receiptsDirectoryName) as NSString)
            .appendingPathComponent(runID)
    }

    public static func loadLog(in worktree: String) -> MollyLifecycleLog {
        let path = lifecycle(in: worktree)
        guard let text = try? String(contentsOfFile: path, encoding: .utf8) else {
            return MollyLifecycleLog()
        }
        return MollyLifecycleLog.load(text: text)
    }

    /// Writes the Artisan JSON onto the existing worktree and returns the bound snapshot.
    ///
    /// Refuses a contract that names a different Bloom workspace, path, or branch. Does not
    /// create a worktree.
    public static func importContract(
        json: String,
        workspace: Workspace,
        baseSHA: String
    ) throws -> MollyTaskSnapshot {
        let contract = try MollyTaskContract.decode(text: json)
        let binding = try MollyWorkspaceBinding.bind(
            contract: contract, workspace: workspace, baseSHA: baseSHA
        )
        try save(binding.contract, in: workspace.path, json: json)
        return loadSnapshot(workspace: workspace)
            ?? MollyTaskSnapshot.make(contract: binding.contract, log: loadLog(in: workspace.path))
    }

    public static func loadContract(in worktree: String) -> MollyTaskContract? {
        let path = contract(in: worktree)
        guard let text = try? String(contentsOfFile: path, encoding: .utf8) else { return nil }
        return try? MollyTaskContract.decode(text: text)
    }

    public static func save(_ contract: MollyTaskContract, in worktree: String, json: String) throws {
        let directory = directory(in: worktree)
        try FileManager.default.createDirectory(atPath: directory, withIntermediateDirectories: true)
        try json.write(toFile: Self.contract(in: worktree), atomically: true, encoding: .utf8)
    }

    public static func append(_ event: MollyLifecycleEvent, in worktree: String) throws {
        let directory = directory(in: worktree)
        try FileManager.default.createDirectory(atPath: directory, withIntermediateDirectories: true)
        let path = lifecycle(in: worktree)
        let line = event.jsonLine + "\n"
        if !FileManager.default.fileExists(atPath: path) {
            FileManager.default.createFile(atPath: path, contents: Data(line.utf8))
            return
        }
        let handle = try FileHandle(forWritingTo: URL(filePath: path))
        defer { try? handle.close() }
        try handle.seekToEnd()
        try handle.write(contentsOf: Data(line.utf8))
    }

    /// Reads a bound contract, lifecycle log, and receipts from this worktree.
    ///
    /// Returns nil when there is no contract, or when the contract names a different
    /// Bloom workspace, path, or branch. That hides the Molly tab rather than showing
    /// another workspace's task.
    public static func loadSnapshot(workspace: Workspace) -> MollyTaskSnapshot? {
        guard let contract = loadContract(in: workspace.path),
              let binding = try? MollyWorkspaceBinding.bind(
                contract: contract,
                workspace: workspace,
                baseSHA: contract.baseSHA
              )
        else { return nil }
        let log = loadLog(in: workspace.path)
        let runID = log.events(taskID: contract.taskID).reversed().compactMap(\.runID).first
        let outcomes = runID.map { loadReceipts(in: workspace.path, runID: $0) } ?? []
        return MollyTaskSnapshot.make(contract: binding.contract, log: log, outcomes: outcomes)
    }

    public static func loadReceipts(in worktree: String, runID: String) -> [MollyVerificationOutcome] {
        let directory = receipts(in: worktree, runID: runID)
        guard let names = try? FileManager.default.contentsOfDirectory(atPath: directory) else {
            return []
        }
        return names.sorted().compactMap { name in
            guard name.hasSuffix(".json") else { return nil }
            let path = (directory as NSString).appendingPathComponent(name)
            guard let text = try? String(contentsOfFile: path, encoding: .utf8),
                  let outcome = try? MollyVerificationOutcome.decode(text: text)
            else { return nil }
            return outcome
        }
    }
}

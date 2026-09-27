import SwiftUI

/// `molly.runs`: pick a task, list its attempts from `molly:task --json`, or open a run by ID.
///
/// Molly has no command that lists runs across tasks, so this screen reads them per task.
struct MollyRunsScreen: View {
    @State private var selectedTask: String?
    @State private var runID = ""

    var body: some View {
        MollyStack {
            VStack(spacing: 0) {
                HStack {
                    TaskPicker(selection: $selectedTask)
                    Spacer()
                    TextField("Run ID", text: $runID)
                        .textFieldStyle(.roundedBorder)
                        .frame(maxWidth: 280)
                    NavigationLink("Open", value: MollyRoute.run(runID.trimmingCharacters(in: .whitespaces)))
                        .disabled(runID.trimmingCharacters(in: .whitespaces).isEmpty)
                }
                .padding(12)
                Divider()
                if let selectedTask {
                    TaskRunsList(taskID: selectedTask)
                } else {
                    ContentUnavailableView("Pick a task", systemImage: "play.circle", description: Text("Choose a task to list its runs, or paste a run ID."))
                }
            }
            .navigationTitle("Runs")
        }
    }
}

struct TaskPicker: View {
    @Binding var selection: String?

    var body: some View {
        CommandView(["molly:tasks", "--limit=50", "--json"]) { json, _ in
            let tasks = (json["tasks"]?.array ?? []).filter { $0["id"]?.string != nil }
            if tasks.isEmpty {
                Text("No tasks yet").foregroundStyle(.secondary)
            } else {
                Picker("Task", selection: $selection) {
                    Text("None").tag(String?.none)
                    ForEach(Array(tasks.enumerated()), id: \.offset) { _, task in
                        Text(task["nickname"]?.string ?? task["id"]?.string ?? "")
                            .tag(task["id"]?.string)
                    }
                }
                .frame(maxWidth: 360)
            }
        }
        .frame(maxHeight: 60)
    }
}

struct TaskRunsList: View {
    var taskID: String

    var body: some View {
        CommandView(["molly:task", taskID, "--json"]) { json, _ in
            let runs = json.at("task.runs")?.array ?? []
            if runs.isEmpty {
                ContentUnavailableView("No runs", systemImage: "play.circle", description: Text("`molly:task \(taskID)` reported no runs. Start the task first."))
            } else {
                List(Array(runs.enumerated()), id: \.offset) { index, run in
                    if let id = run["id"]?.string {
                        NavigationLink(value: MollyRoute.run(id)) {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text("Attempt \(index + 1) · \(id)").font(.body.monospaced())
                                    Text("Pest \(run.at("report.verification.status")?.string ?? "not run") · created \(run["created_at"]?.string ?? "not reported")")
                                        .font(.caption).foregroundStyle(.secondary)
                                }
                                Spacer()
                                StatusBadge(status: run["status"]?.string)
                            }
                        }
                    }
                }
            }
        }
    }
}

/// `molly:inspect --run=<id> --json`: status, loop, verification, Tarpit checks, Clever
/// measurements, and links to the task, conversation, diff, and receipts.
struct RunDetailView: View {
    var runID: String

    var body: some View {
        CommandView(["molly:inspect", "--run=\(runID)", "--json"]) { json, _ in
            let inspection = json["inspection"] ?? json
            let report = inspection["report"] ?? .object([:])
            let workspace = inspection.at("run.workspace")?.string
            Form {
                Section {
                    FieldRow("Run ID", inspection.at("run.id"))
                    LabeledContent("Status") { StatusBadge(status: inspection.at("run.status")?.string) }
                    FieldRow("Summary", inspection.at("outputs.summary"))
                    FieldRow("Workspace", inspection.at("run.workspace"))
                    FieldRow("Phase", inspection.at("loop.phase"))
                    FieldRow("Agent", inspection.at("loop.agent"))
                    FieldRow("Model", inspection.at("loop.model"))
                    FieldRow("Iterations", inspection.at("loop.iterations"))
                    FieldRow("Started", inspection.at("timing.started_at"))
                    FieldRow("Finished", inspection.at("timing.finished_at"))
                    FieldRow("Stop reason", inspection["stop_reason"])
                    FieldRow("Error", report["error"])
                }

                Section("Linked records") {
                    if let taskID = inspection.at("task.id")?.string ?? inspection.at("run.task_id")?.string {
                        NavigationLink("Task \(inspection.at("task.reference")?.string ?? taskID)", value: MollyRoute.task(taskID))
                    }
                    if let conversation = inspection["conversation_id"]?.string {
                        NavigationLink("Conversation \(conversation)", value: MollyRoute.conversation(conversation))
                    } else {
                        Text("No conversation recorded for this run.").foregroundStyle(.secondary)
                    }
                    if let workspace {
                        NavigationLink("Diff of \(workspace)", value: MollyRoute.diff(workspace: workspace, runID: runID))
                    }
                    NavigationLink("Verification receipts", value: MollyRoute.receipts(runID: runID, workspace: workspace))
                }

                Section("Changed files") {
                    let changes = inspection.at("outputs.changes")?.array ?? []
                    if changes.isEmpty { Text("No changed files reported.").foregroundStyle(.secondary) }
                    ForEach(Array(changes.enumerated()), id: \.offset) { _, change in
                        LabeledContent(change["path"]?.string ?? "file", value: change["status"]?.string ?? "changed")
                    }
                }

                Section("Completion blockers") {
                    let blockers = report["completion_blockers"]?.array ?? []
                    if blockers.isEmpty {
                        Text(report["completion_blockers"] == nil ? "Not reported." : "None recorded.").foregroundStyle(.secondary)
                    }
                    ForEach(Array(blockers.enumerated()), id: \.offset) { _, blocker in
                        Text(blocker.display).font(.caption.monospaced()).textSelection(.enabled)
                    }
                }

                VerificationSection(report: report)
                TarpitSection(review: report["review"])
                CleverSection(report: report)

                if let config = inspection["effective_config"], !config.isNull {
                    Section { RawJSONDisclosure(title: "Effective configuration for this run", json: config) }
                }
                Section { RawJSONDisclosure(json: inspection) }
            }
            .formStyle(.grouped)
        }
        .navigationTitle("Run")
    }
}

struct VerificationSection: View {
    var report: JSONValue

    var body: some View {
        Section("Pest") {
            let verification = report["verification"]
            LabeledContent("Result") { StatusBadge(status: verification?["status"]?.string ?? "not run") }
            FieldRow("Tests", verification?["tests"])
            FieldRow("Assertions", verification?["assertions"])
            if let reason = verification?["reason"], !reason.isNull { FieldRow("Reason", reason) }
            if let output = verification?["output"]?.string, !output.isEmpty {
                DisclosureGroup("Pest output") { OutputBlock(title: "", text: output) }
            }
        }
    }
}

/// Every Tarpit check with its recorded status, then every unresolved finding. A skipped check
/// keeps its own status and is never shown as passing.
struct TarpitSection: View {
    var review: JSONValue?

    var body: some View {
        Section("Tarpit review") {
            LabeledContent("Review") { StatusBadge(status: review?["status"]?.string ?? (review?["checks"] == nil ? "not run" : "see checks")) }
            let checks = (review?["checks"]?.object ?? [:]).sorted { $0.key < $1.key }
            if review != nil, checks.isEmpty {
                Text("The report lists no Tarpit checks.").foregroundStyle(.secondary)
            }
            ForEach(checks, id: \.key) { key, check in
                VStack(alignment: .leading, spacing: 2) {
                    HStack {
                        Text(key).font(.body.monospaced())
                        Spacer()
                        StatusBadge(status: check["status"]?.string)
                    }
                    if let evidence = check["evidence"]?.string, !evidence.isEmpty {
                        Text(evidence).font(.caption).foregroundStyle(.secondary).textSelection(.enabled)
                    }
                }
            }
            let findings = review?["findings"]?.array ?? []
            ForEach(Array(findings.enumerated()), id: \.offset) { _, finding in
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(finding["severity"]?.string ?? "finding") · \(finding["classification"]?.string ?? "") · \(finding["path"]?.string ?? ""):\(finding["line"]?.string ?? "")")
                        .font(.caption.monospaced())
                    Text(finding["problem"]?.string ?? "").textSelection(.enabled)
                    if let fix = finding["recommendation"]?.string {
                        Text("Suggested change: \(fix)").font(.caption).foregroundStyle(.secondary)
                    }
                }
            }
        }
    }
}

/// Clever probes before and after, side by side, with skip reasons and caveats. They are not
/// combined into a score.
struct CleverSection: View {
    var report: JSONValue

    private func probes(_ key: String) -> [String: JSONValue] {
        var byKey: [String: JSONValue] = [:]
        for probe in report.at("\(key).probes")?.array ?? [] {
            if let id = probe["key"]?.string { byKey[id] = probe }
        }
        return byKey
    }

    var body: some View {
        let before = probes("complexity_before")
        let after = probes("complexity_after")
        let keys = Set(before.keys).union(after.keys).sorted()
        Section("Clever measurements") {
            LabeledContent("Before changes") { StatusBadge(status: report.at("complexity_before.status")?.string ?? "not run") }
            LabeledContent("After changes") { StatusBadge(status: report.at("complexity_after.status")?.string ?? "not run") }
            ForEach(["complexity_before", "complexity_after"], id: \.self) { key in
                if let reason = report.at("\(key).reason")?.string {
                    Text("\(key == "complexity_before" ? "Before" : "After"): \(reason)").font(.caption).foregroundStyle(.secondary)
                }
            }
            if !keys.isEmpty {
                Text("Clever measures code structure. Lower counts alone do not prove a simpler design.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            ForEach(keys, id: \.self) { key in
                CleverProbeRow(key: key, before: before[key], after: after[key])
            }
        }
    }
}

struct CleverProbeRow: View {
    var key: String
    var before: JSONValue?
    var after: JSONValue?

    var body: some View {
        let probe = after ?? before
        let metricNames = Set((before?["metrics"]?.object ?? [:]).keys).union((after?["metrics"]?.object ?? [:]).keys).sorted()
        VStack(alignment: .leading, spacing: 4) {
            Text("\(key) · \(probe?["name"]?.string ?? "probe")").font(.body.weight(.medium))
            Grid(alignment: .leading, horizontalSpacing: 16, verticalSpacing: 2) {
                GridRow {
                    Text("Measurement").foregroundStyle(.secondary)
                    Text("Before").foregroundStyle(.secondary)
                    Text("After").foregroundStyle(.secondary)
                }
                GridRow {
                    Text("status")
                    Text(before?["status"]?.string ?? "not run")
                    Text(after?["status"]?.string ?? "not run")
                }
                ForEach(metricNames, id: \.self) { name in
                    GridRow {
                        Text(name.replacingOccurrences(of: "_", with: " "))
                        Text(before?["metrics"]?[name]?.display ?? "not measured")
                        Text(after?["metrics"]?[name]?.display ?? "not measured")
                    }
                }
            }
            .font(.caption.monospaced())
            ForEach([("Before", before), ("After", after)], id: \.0) { label, value in
                if let skip = value?["skip_reason"], !skip.isNull {
                    Text("\(label) skipped: \(skip.display)").font(.caption).foregroundStyle(.orange)
                }
                ForEach(Array(((value?["caveats"]?.array ?? []) + (value?["warnings"]?.array ?? [])).enumerated()), id: \.offset) { _, caveat in
                    Text("\(label): \(caveat.display)").font(.caption).foregroundStyle(.secondary)
                }
            }
        }
    }
}

/// `git -C <workspace> diff`: the uncommitted changes in the run's workspace right now.
struct DiffView: View {
    var workspace: String
    var runID: String
    @State private var stat: MollyRunner.RawResult?
    @State private var diff: MollyRunner.RawResult?

    private var diffInvocation: Invocation { Invocation(executable: "/usr/bin/git", arguments: ["-C", workspace, "diff"], cwd: "") }
    private var statInvocation: Invocation { Invocation(executable: "/usr/bin/git", arguments: ["-C", workspace, "diff", "--stat"], cwd: "") }

    var body: some View {
        Group {
            if let diff, let stat {
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        Text("Current uncommitted changes in \(workspace). This is the working tree now, not a snapshot saved with run \(runID).")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Text(diffInvocation.display).font(.caption.monospaced()).textSelection(.enabled)
                        if diff.exitCode != 0 || diff.launchError != nil {
                            Label("git diff failed with exit code \(diff.exitCode)", systemImage: "exclamationmark.triangle").foregroundStyle(.red)
                            OutputBlock(title: "stderr", text: diff.launchError ?? diff.stderr)
                        } else if diff.stdout.isEmpty {
                            ContentUnavailableView("No uncommitted changes", systemImage: "doc.text.magnifyingglass", description: Text("`git diff` printed nothing for this workspace."))
                        } else {
                            OutputBlock(title: "git diff --stat", text: stat.stdout)
                            Text(diff.stdout)
                                .font(.caption.monospaced())
                                .textSelection(.enabled)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                    }
                    .padding(16)
                }
            } else {
                CommandLoadingView(command: diffInvocation.display)
            }
        }
        .navigationTitle("Diff")
        .task(id: workspace) {
            let runner = MollyModel.shared.runner
            stat = await runner.run(statInvocation)
            diff = await runner.run(diffInvocation)
        }
    }
}

/// `molly:receipt <run> --json`: the immutable verifier receipts saved for a run.
struct ReceiptsView: View {
    var runID: String
    var workspace: String?

    private var arguments: [String] {
        var arguments = ["molly:receipt", runID, "--json"]
        if let workspace { arguments.append("--workspace=\(workspace)") }
        return arguments
    }

    var body: some View {
        CommandView(arguments) { json, _ in
            let receipts = json["receipts"]?.array ?? []
            Form {
                if receipts.isEmpty {
                    Text("`molly:receipt` returned no receipts.").foregroundStyle(.secondary)
                }
                ForEach(Array(receipts.enumerated()), id: \.offset) { _, receipt in
                    Section(receipt["verifier"]?.string ?? "verifier") {
                        LabeledContent("State") { StatusBadge(status: receipt["state"]?.string) }
                        FieldRow("Policy", receipt["policy"])
                        FieldRow("Failure action", receipt["failure_action"])
                        FieldRow("Another attempt permitted", receipt["another_attempt_permitted"])
                        FieldRow("Evidence digest", receipt["evidence_digest"])
                        FieldRow("Diagnostics", receipt["diagnostics_ref"])
                        FieldRow("Started", receipt["started_at"])
                        FieldRow("Finished", receipt["finished_at"])
                        FieldRow("File", receipt["path"])
                    }
                }
            }
            .formStyle(.grouped)
        }
        .navigationTitle("Receipts")
    }
}

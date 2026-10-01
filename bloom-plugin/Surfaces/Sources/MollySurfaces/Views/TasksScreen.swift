import SwiftUI

/// `molly.tasks`: saved tasks from `molly:tasks --json`, a plain-English create form, and the
/// task detail with start, stop, retry, and approve.
struct MollyTasksScreen: View {
    @State private var showingCreate = false

    var body: some View {
        MollyStack {
            TaskListView()
                .navigationTitle("Tasks")
                .toolbar {
                    ToolbarItem {
                        Button("New Task", systemImage: "plus") { showingCreate = true }
                    }
                }
                .sheet(isPresented: $showingCreate) { CreateTaskSheet() }
        }
    }
}

struct TaskListView: View {
    var body: some View {
        CommandView(["molly:tasks", "--limit=50", "--json"]) { json, _ in
            let tasks = json["tasks"]?.array ?? []
            if tasks.isEmpty {
                ContentUnavailableView {
                    Label("No tasks yet", systemImage: "checklist")
                } description: {
                    Text("`molly:tasks` returned no saved tasks. Use New Task to describe a change in plain English.")
                }
            } else {
                List(Array(tasks.enumerated()), id: \.offset) { _, task in
                    if let id = task["id"]?.string {
                        NavigationLink(value: MollyRoute.task(id)) {
                            TaskRow(task: task)
                        }
                    }
                }
            }
        }
    }
}

struct TaskRow: View {
    var task: JSONValue

    var body: some View {
        HStack(alignment: .firstTextBaseline) {
            VStack(alignment: .leading, spacing: 2) {
                Text(task["nickname"]?.string ?? task["id"]?.string ?? "Task")
                    .font(.body.weight(.medium))
                Text(task["prompt"]?.string ?? "")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(2)
            }
            Spacer()
            StatusBadge(status: task["status"]?.string)
        }
    }
}

struct CreateTaskSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State private var model = MollyModel.shared
    @State private var prompt = ""
    @State private var name = ""
    @State private var files = ""
    @State private var testPath = ""
    @State private var workspace = ""
    @State private var action = ActionState()

    var body: some View {
        Form {
            Section("Describe the change") {
                TextEditor(text: $prompt)
                    .font(.body)
                    .frame(minHeight: 90)
                TextField("Name (optional)", text: $name)
            }
            Section("Scope") {
                TextField("Workspace", text: $workspace, prompt: Text(model.projectPath ?? "Repository path"))
                TextField("Allowed files, comma separated", text: $files)
                TextField("Required Pest test (optional)", text: $testPath)
                Text("Molly saves the task without running a model or changing files. Start it from the task page.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            ActionStatusView(state: action)
        }
        .formStyle(.grouped)
        .frame(minWidth: 520, minHeight: 440)
        .toolbar {
            ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } }
            ToolbarItem(placement: .confirmationAction) {
                Button("Save Task") { create() }
                    .disabled(prompt.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || action.running != nil)
            }
        }
    }

    private func create() {
        var arguments = ["molly:create", prompt, "--json"]
        let workspacePath = workspace.isEmpty ? model.projectPath : workspace
        if let workspacePath { arguments.append("--workspace=\(workspacePath)") }
        for file in files.split(separator: ",").map({ $0.trimmingCharacters(in: .whitespaces) }) where !file.isEmpty {
            arguments.append("--file=\(file)")
        }
        if !testPath.isEmpty { arguments.append("--test=\(testPath)") }
        if !name.isEmpty { arguments.append("--name=\(name)") }
        Task {
            await action.run(arguments) { _ in dismiss() }
        }
    }
}

/// `molly:inspect --task=<id> --json`, with links to every run and conversation.
struct TaskDetailView: View {
    var taskID: String
    @State private var action = ActionState()
    @State private var confirmingApproval = false

    var body: some View {
        CommandView(["molly:inspect", "--task=\(taskID)", "--json"]) { json, _ in
            let inspection = json["inspection"] ?? json
            let task = inspection["task"] ?? .object([:])
            let reference = task["reference"]?.string ?? task["nickname"]?.string ?? taskID
            Form {
                Section {
                    FieldRow("Task", task["nickname"] ?? task["id"])
                    FieldRow("Task ID", task["id"])
                    LabeledContent("Status") { StatusBadge(status: task["status"]?.string) }
                    FieldRow("Display status", task["display_status"])
                    FieldRow("Prompt", task["prompt"])
                    FieldRow("Workspace", task["workspace"])
                    FieldRow("Allowed files", .string((task["paths"]?.array ?? []).compactMap(\.string).joined(separator: "\n")))
                    FieldRow("Required test", task["test_path"])
                    FieldRow("Loop phase", inspection.at("loop.phase"))
                    FieldRow("Failure reason", inspection["failure_reason"])
                    FieldRow("Linked pull request", inspection["linked_pr"])
                    FieldRow("Issue", inspection["issue_url"])
                }

                Section("Actions") {
                    HStack {
                        Button("Start") { run(["molly:start", reference, "--json"]) }
                        Button("Stop") { run(["molly:stop", reference, "--json"]) }
                        Button("Retry") { run(["molly:retry", reference, "--json"]) }
                        Button("Approve…") { confirmingApproval = true }
                    }
                    .disabled(action.running != nil)
                    Text("Start and Retry run the model and verification. They can take several minutes.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    ActionStatusView(state: action)
                }
                .confirmationDialog("Record human approval for \(reference)?", isPresented: $confirmingApproval) {
                    Button("Approve \(reference)") { run(["molly:approve", reference, "--approve", "--json"]) }
                    Button("Cancel", role: .cancel) {}
                } message: {
                    Text("This runs `molly:approve \(reference) --approve`. Molly records that you read the verified change. Bloom's pull request controls unlock after it. Molly does not open or merge a pull request.")
                }

                Section("Runs") {
                    let runs = inspection["runs"]?.array ?? []
                    if runs.isEmpty {
                        Text("No runs yet. Start the task to create one.").foregroundStyle(.secondary)
                    }
                    ForEach(Array(runs.enumerated()), id: \.offset) { index, run in
                        if let id = run["id"]?.string {
                            NavigationLink(value: MollyRoute.run(id)) {
                                HStack {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text("Attempt \(index + 1) · \(id)").font(.body.monospaced())
                                        Text(run["summary"]?.string ?? run["created_at"]?.string ?? "")
                                            .font(.caption).foregroundStyle(.secondary).lineLimit(2)
                                    }
                                    Spacer()
                                    StatusBadge(status: run["status"]?.string)
                                }
                            }
                        }
                    }
                }

                Section("Conversations") {
                    let ids = (inspection["conversation_ids"]?.array ?? []).compactMap(\.string)
                    if ids.isEmpty {
                        Text("No saved conversations for this task.").foregroundStyle(.secondary)
                    }
                    ForEach(ids, id: \.self) { id in
                        NavigationLink(id, value: MollyRoute.conversation(id))
                    }
                }

                Section { RawJSONDisclosure(json: inspection) }
            }
            .formStyle(.grouped)
        }
        .navigationTitle("Task")
    }

    private func run(_ arguments: [String]) {
        Task { await action.run(arguments) }
    }
}

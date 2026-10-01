import SwiftUI

/// `molly.home`: pick the project every other Molly screen reads, create a new one with
/// `molly:project-new`, add an existing Laravel app with `molly:project-init`, and read the
/// knowledge graph bootstrap state.
struct MollyHomeScreen: View {
    @State private var model = MollyModel.shared
    @State private var showingNewProject = false
    @State private var action = ActionState()

    var body: some View {
        Form {
            Section("Project") {
                if model.projects.isEmpty {
                    Text("No projects in \(model.index.indexPath).")
                        .foregroundStyle(.secondary)
                } else {
                    Picker("Project", selection: Binding(
                        get: { model.selectedProjectPath ?? "" },
                        set: { model.selectedProjectPath = $0.isEmpty ? nil : $0 }
                    )) {
                        ForEach(model.projects) { project in
                            Text("\(project.name)  \(project.path)").tag(project.path)
                        }
                    }
                }
                if let problem = model.indexProblem {
                    Label(problem, systemImage: "exclamationmark.triangle").foregroundStyle(.red)
                }
                FieldRow("Artisan runs in", text: model.host ?? "No Laravel app with an artisan file found")
                FieldRow("PHP", text: PHPLocator.resolve(environment: model.environment) { FileManager.default.isExecutableFile(atPath: $0) } ?? "/usr/bin/env php (not found in the usual places)")
                FieldRow("Project index", text: model.index.indexPath)
                HStack {
                    Button("New Project…") { showingNewProject = true }
                        .disabled(model.host == nil)
                    Button("Add Existing…") { addExisting() }
                    Button("Reload") { model.reloadProjects() }
                }
                if model.host == nil {
                    Text("New Project needs a Laravel app with Molly to run `molly:project-new` from. Add an existing app first, or set MOLLY_ARTISAN_HOST before launching Bloom.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                ActionStatusView(state: action)
            }

            if let path = model.projectPath {
                GraphBootstrapSection(projectPath: path)
            }
        }
        .formStyle(.grouped)
        .navigationTitle("Molly")
        .sheet(isPresented: $showingNewProject) {
            NewProjectSheet { path in
                model.reloadProjects()
                model.selectedProjectPath = path
            }
        }
    }

    private func addExisting() {
        guard let folder = MollyPanels.chooseFolder(prompt: "Add to Molly") else { return }
        // A Laravel app with Molly installed can initialize itself. Otherwise the current host
        // initializes the chosen folder by path.
        let selfHosted = FileManager.default.isReadableFile(atPath: folder + "/artisan")
            && FileManager.default.fileExists(atPath: folder + "/vendor/sifrious/molly")
        let arguments = selfHosted ? ["molly:project-init", "--json"] : ["molly:project-init", folder, "--json"]
        Task {
            await action.run(arguments, host: selfHosted ? folder : nil) { outcome in
                model.reloadProjects()
                model.selectedProjectPath = outcome.json?.at("project.path")?.string ?? folder
            }
        }
    }
}

struct NewProjectSheet: View {
    var onCreated: (String) -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var parent = ""
    @State private var folder = ""
    @State private var name = ""
    @State private var skipGraphs = false
    @State private var action = ActionState()

    private var target: String {
        parent.isEmpty || folder.isEmpty ? "" : (parent as NSString).appendingPathComponent(folder)
    }

    var body: some View {
        Form {
            Section("New Laravel project with Molly") {
                HStack {
                    TextField("Parent folder", text: $parent)
                    Button("Choose…") { parent = MollyPanels.chooseFolder(prompt: "Choose") ?? parent }
                }
                TextField("Folder name", text: $folder)
                TextField("Display name", text: $name)
                Toggle("Skip knowledge graph bootstrap (--no-graphs)", isOn: $skipGraphs)
                FieldRow("Creates", text: target.isEmpty ? nil : target)
                Text("Runs `molly:project-new`, which calls Composer create-project and can take several minutes.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            ActionStatusView(state: action)
        }
        .formStyle(.grouped)
        .frame(minWidth: 520, minHeight: 360)
        .toolbar {
            ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } }
            ToolbarItem(placement: .confirmationAction) {
                Button("Create") { create() }
                    .disabled(target.isEmpty || action.running != nil)
            }
        }
    }

    private func create() {
        var arguments = ["molly:project-new", target, "--json"]
        if !name.isEmpty { arguments.append("--name=\(name)") }
        if skipGraphs { arguments.append("--no-graphs") }
        Task {
            await action.run(arguments) { outcome in
                onCreated(outcome.json?.at("project.path")?.string ?? target)
            }
        }
    }
}

/// Reads `<project>/.molly/graphs/manifest.json`, the file `molly:graphs-bootstrap` writes.
struct GraphBootstrapSection: View {
    var projectPath: String
    @State private var action = ActionState()
    @State private var model = MollyModel.shared

    private var manifestPath: String { projectPath + "/.molly/graphs/manifest.json" }

    private var manifest: JSONValue? {
        _ = model.revision
        guard let data = FileManager.default.contents(atPath: manifestPath) else { return nil }
        return JSONValue.parse(String(decoding: data, as: UTF8.self))
    }

    var body: some View {
        Section("Knowledge graphs") {
            FieldRow("Manifest", text: manifestPath)
            if let manifest {
                FieldRow("Laravel", manifest["laravel_exact"])
                FieldRow("Updated", manifest["updated_at"])
                ForEach(Array((manifest["units"]?.array ?? []).enumerated()), id: \.offset) { _, unit in
                    HStack(alignment: .firstTextBaseline) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(unit["package"]?.string ?? unit["id"]?.string ?? "unit")
                            Text("\(unit["exact_version"]?.string ?? "version not reported") · source \(unit["source"]?.string ?? "none")")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            if let error = unit["error"]?.string {
                                Text(error).font(.caption).foregroundStyle(.red).textSelection(.enabled)
                            }
                        }
                        Spacer()
                        StatusBadge(status: unit["status"]?.string)
                    }
                }
            } else {
                Text("No graph manifest yet. Molly has not bootstrapped graphs for this project.")
                    .foregroundStyle(.secondary)
            }
            Button("Bootstrap Graphs") {
                Task { await action.run(["molly:graphs-bootstrap", projectPath, "--json"]) }
            }
            .disabled(action.running != nil)
            ActionStatusView(state: action)
        }
    }
}

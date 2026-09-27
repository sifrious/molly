import SwiftUI

/// One scalar setting, addressed by its dotted path such as `loop.max_iterations`.
struct SettingLeaf: Identifiable, Hashable {
    var path: [String]
    var value: JSONValue
    var defaultValue: JSONValue?
    var id: String { path.joined(separator: ".") }

    static func flatten(_ value: JSONValue, defaults: JSONValue?, prefix: [String] = []) -> [SettingLeaf] {
        guard let object = value.object else {
            return [SettingLeaf(path: prefix, value: value, defaultValue: defaults)]
        }
        return object.keys.sorted().flatMap { key in
            flatten(object[key]!, defaults: defaults?[key], prefix: prefix + [key])
        }
    }

    /// Reads edited text back as the type the current value has. Empty text on a null setting
    /// stays null.
    func parse(_ text: String) -> JSONValue? {
        switch value {
        case .number:
            return Double(text).map(JSONValue.number)
        case .null:
            return text.isEmpty ? .null : .string(text)
        case .string:
            return .string(text)
        default:
            return nil
        }
    }

    /// Builds the nested object `molly:settings-set --patch` expects.
    static func patch(_ changes: [([String], JSONValue)]) -> JSONValue {
        func insert(_ path: ArraySlice<String>, _ value: JSONValue, into object: [String: JSONValue]) -> [String: JSONValue] {
            var object = object
            guard let head = path.first else { return object }
            if path.count == 1 {
                object[head] = value
            } else {
                object[head] = .object(insert(path.dropFirst(), value, into: object[head]?.object ?? [:]))
            }
            return object
        }
        return .object(changes.reduce([:]) { insert($1.0[...], $1.1, into: $0) })
    }
}

/// `molly.settings`: `molly:settings --json` shows the merged settings Molly applies to new runs
/// next to the documented defaults; edits go through `molly:settings-set --patch`.
struct MollySettingsScreen: View {
    var body: some View {
        MollyStack {
            CommandView(["molly:settings", "--json"]) { json, _ in
                SettingsForm(json: json)
            }
            .navigationTitle("Molly Settings")
        }
    }
}

struct SettingsForm: View {
    var json: JSONValue
    @State private var edits: [String: String] = [:]
    @State private var toggles: [String: Bool] = [:]
    @State private var action = ActionState()

    private var leaves: [SettingLeaf] {
        SettingLeaf.flatten(json["settings"] ?? .object([:]), defaults: json["defaults"])
    }

    var body: some View {
        let groups = Dictionary(grouping: leaves) { $0.path.first ?? "" }
        Form {
            Section {
                FieldRow("Settings file", json["path"])
                FieldRow("Schema version", json["schema_version"])
                Text("These are the documented defaults merged with your saved overrides: the configuration Molly applies to new runs. Each run keeps its own effective configuration on the run page. Changing a setting does not rewrite past runs.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            ForEach(groups.keys.sorted(), id: \.self) { group in
                Section(group.replacingOccurrences(of: "_", with: " ")) {
                    ForEach(groups[group] ?? []) { leaf in
                        row(leaf)
                    }
                }
            }
            Section {
                HStack {
                    Button("Save Changes") { save() }
                        .disabled(changes.isEmpty || action.running != nil)
                    Button("Discard") { edits = [:]; toggles = [:] }
                        .disabled(changes.isEmpty)
                }
                if !changes.isEmpty {
                    Text("Patch: \(SettingLeaf.patch(changes).compact)")
                        .font(.caption.monospaced())
                        .textSelection(.enabled)
                }
                ActionStatusView(state: action)
            }
        }
        .formStyle(.grouped)
    }

    @ViewBuilder
    private func row(_ leaf: SettingLeaf) -> some View {
        let label = leaf.path.dropFirst().joined(separator: ".").replacingOccurrences(of: "_", with: " ")
        let overridden = leaf.defaultValue != nil && leaf.defaultValue != leaf.value
        VStack(alignment: .leading, spacing: 2) {
            switch leaf.value {
            case .bool(let current):
                Toggle(label, isOn: Binding(
                    get: { toggles[leaf.id] ?? current },
                    set: { toggles[leaf.id] = $0 }
                ))
            case .string, .number, .null:
                LabeledContent(label) {
                    TextField(label, text: Binding(
                        get: { edits[leaf.id] ?? (leaf.value.isNull ? "" : leaf.value.display) },
                        set: { edits[leaf.id] = $0 }
                    ), prompt: Text(leaf.value.isNull ? "null" : ""))
                    .labelsHidden()
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: 280)
                }
            default:
                LabeledContent(label, value: leaf.value.compact)
            }
            if overridden {
                Text("Override. Default: \(leaf.defaultValue?.compact ?? "")")
                    .font(.caption)
                    .foregroundStyle(.orange)
            }
        }
    }

    private var changes: [([String], JSONValue)] {
        var result: [([String], JSONValue)] = []
        for leaf in leaves {
            if let toggled = toggles[leaf.id], toggled != leaf.value.bool {
                result.append((leaf.path, .bool(toggled)))
            }
            if let text = edits[leaf.id], let parsed = leaf.parse(text), parsed != leaf.value {
                result.append((leaf.path, parsed))
            }
        }
        return result
    }

    private func save() {
        let patch = SettingLeaf.patch(changes).compact
        Task {
            await action.run(["molly:settings-set", "--patch=\(patch)", "--json"]) { _ in
                edits = [:]
                toggles = [:]
            }
        }
    }
}

/// `molly.worker`: `molly:status --json` and `molly:worker {status|start|stop|restart} --json`.
struct MollyWorkerScreen: View {
    @State private var action = ActionState()

    var body: some View {
        MollyStack {
            Form {
                Section("Worker") {
                    CommandView(["molly:worker", "status", "--json"]) { json, _ in
                        VStack(alignment: .leading, spacing: 6) {
                            LabeledContent("State") {
                                StatusBadge(status: json["state"]?.string ?? json.at("worker.state")?.string ?? json["status"]?.string)
                            }
                            FieldRow("PID", json["pid"] ?? json.at("worker.pid"))
                            FieldRow("Started", json["started_at"] ?? json.at("worker.started_at"))
                            FieldRow("Heartbeat", json["heartbeat_at"] ?? json.at("worker.heartbeat_at"))
                            FieldRow("Log", json["log"] ?? json["log_path"] ?? json.at("worker.log"))
                            RawJSONDisclosure(json: json)
                        }
                    }
                    .frame(minHeight: 120)
                    HStack {
                        Button("Start") { run("start") }
                        Button("Stop") { run("stop") }
                        Button("Restart") { run("restart") }
                    }
                    .disabled(action.running != nil)
                    ActionStatusView(state: action)
                }
                Section("Molly status") {
                    CommandView(["molly:status", "--json"]) { json, _ in
                        VStack(alignment: .leading, spacing: 6) {
                            ForEach((json.object ?? [:]).keys.sorted().filter { json[$0]?.isScalar == true }, id: \.self) { key in
                                FieldRow(key.replacingOccurrences(of: "_", with: " "), json[key])
                            }
                            RawJSONDisclosure(json: json)
                        }
                    }
                    .frame(minHeight: 120)
                }
            }
            .formStyle(.grouped)
            .navigationTitle("Worker")
        }
    }

    private func run(_ verb: String) {
        Task { await action.run(["molly:worker", verb, "--json"]) }
    }
}

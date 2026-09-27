import SwiftUI

/// `molly.conversations`: `molly:inspect --conversations --json`.
struct MollyConversationsScreen: View {
    var body: some View {
        MollyStack {
            CommandView(["molly:inspect", "--conversations", "--json"]) { json, _ in
                let conversations = json["conversations"]?.array ?? []
                if conversations.isEmpty {
                    ContentUnavailableView {
                        Label("No conversations", systemImage: "bubble.left.and.bubble.right")
                    } description: {
                        Text("Molly saves a conversation when a run records one. Store: \(json["path"]?.string ?? "not reported")")
                    }
                } else {
                    List(Array(conversations.enumerated()), id: \.offset) { _, conversation in
                        if let id = conversation["id"]?.string {
                            NavigationLink(value: MollyRoute.conversation(id)) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(conversation["title"]?.string ?? id).font(.body.weight(.medium))
                                    Text("\(conversation["message_count"]?.string ?? "0") messages · \(conversation["updated_at"]?.string ?? "")")
                                        .font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
            }
            .navigationTitle("Conversations")
        }
    }
}

/// `molly:inspect --conversation=<id> --json`, linking back to its tasks and runs.
struct ConversationDetailView: View {
    var conversationID: String

    var body: some View {
        CommandView(["molly:inspect", "--conversation=\(conversationID)", "--json"]) { json, _ in
            let conversation = json["conversation"] ?? json
            Form {
                Section {
                    FieldRow("Conversation", conversation["id"])
                    FieldRow("Title", conversation["title"])
                    FieldRow("Workspace", conversation["workspace"])
                    FieldRow("Updated", conversation["updated_at"])
                }
                Section("Linked records") {
                    ForEach((conversation["task_ids"]?.array ?? []).compactMap(\.string), id: \.self) { id in
                        NavigationLink("Task \(id)", value: MollyRoute.task(id))
                    }
                    ForEach((conversation["run_ids"]?.array ?? []).compactMap(\.string), id: \.self) { id in
                        NavigationLink("Run \(id)", value: MollyRoute.run(id))
                    }
                }
                Section("Messages") {
                    let messages = conversation["messages"]?.array ?? []
                    if messages.isEmpty { Text("No messages saved.").foregroundStyle(.secondary) }
                    ForEach(Array(messages.enumerated()), id: \.offset) { _, message in
                        VStack(alignment: .leading, spacing: 2) {
                            Text(message["role"]?.string ?? message["author"]?.string ?? "message")
                                .font(.caption.weight(.semibold)).foregroundStyle(.secondary)
                            Text(message["content"]?.string ?? message["text"]?.string ?? message.display)
                                .textSelection(.enabled)
                        }
                    }
                }
            }
            .formStyle(.grouped)
        }
        .navigationTitle("Conversation")
    }
}

/// `molly.graph`: `molly:project:query <concept> --json`. Every node and edge lists the sources
/// Molly recorded for it.
struct MollyGraphScreen: View {
    @State private var model = MollyModel.shared
    @State private var concept = ""
    @State private var submitted: String?
    @State private var depth = 2
    @State private var limit = 20

    var body: some View {
        MollyStack {
            VStack(spacing: 0) {
                HStack {
                    TextField("Task, test, file, run, or blocker", text: $concept)
                        .textFieldStyle(.roundedBorder)
                        .onSubmit { submitted = concept }
                    Stepper("Depth \(depth)", value: $depth, in: 0...3)
                    Stepper("Limit \(limit)", value: $limit, in: 1...40)
                    Button("Query") { submitted = concept }
                        .disabled(concept.trimmingCharacters(in: .whitespaces).isEmpty)
                }
                .padding(12)
                Divider()
                if let submitted, !submitted.isEmpty {
                    GraphResultView(arguments: arguments(for: submitted))
                } else {
                    ContentUnavailableView("Query the project graph", systemImage: "point.3.connected.trianglepath.dotted", description: Text("Molly reads a bounded neighborhood from the project graph built from saved tasks and attempts."))
                }
            }
            .navigationTitle("Graph")
        }
    }

    private func arguments(for concept: String) -> [String] {
        var arguments = ["molly:project:query", concept, "--depth=\(depth)", "--limit=\(limit)", "--json"]
        if let path = model.projectPath { arguments.append("--workspace=\(path)") }
        return arguments
    }
}

struct GraphResultView: View {
    var arguments: [String]

    var body: some View {
        CommandView(arguments) { json, _ in
            let nodes = json["nodes"]?.array ?? []
            let edges = json["edges"]?.array ?? []
            let labels = Dictionary(nodes.compactMap { node in node["id"]?.string.map { ($0, node["label"]?.string ?? $0) } }, uniquingKeysWith: { first, _ in first })
            Form {
                Section {
                    FieldRow("Namespace", json["namespace"])
                    FieldRow("Version", json["version"])
                    FieldRow("Database", json["database"])
                    FieldRow("Truncated", json["truncated"])
                }
                Section("Nodes (\(nodes.count))") {
                    if nodes.isEmpty { Text("No nodes matched.").foregroundStyle(.secondary) }
                    ForEach(Array(nodes.enumerated()), id: \.offset) { _, node in
                        VStack(alignment: .leading, spacing: 2) {
                            HStack {
                                Text(node["label"]?.string ?? "node").font(.body.weight(.medium))
                                Spacer()
                                Text(node["type"]?.string ?? "").font(.caption).foregroundStyle(.secondary)
                            }
                            if let route = route(for: node) {
                                NavigationLink("Open \(node["type"]?.string ?? "record")", value: route).font(.caption)
                            }
                            ProvenanceList(sources: node["sources"]?.array ?? [])
                        }
                    }
                }
                Section("Edges (\(edges.count))") {
                    ForEach(Array(edges.enumerated()), id: \.offset) { _, edge in
                        VStack(alignment: .leading, spacing: 2) {
                            let from = edge["from"]?.string ?? ""
                            let to = edge["to"]?.string ?? ""
                            Text("\(labels[from] ?? from)  \(edge["relation"]?.string ?? "relates to")  \(labels[to] ?? to)")
                            ProvenanceList(sources: edge["sources"]?.array ?? [])
                        }
                    }
                }
                Section { RawJSONDisclosure(json: json) }
            }
            .formStyle(.grouped)
        }
    }

    /// Task and run nodes link to their records when the node key is their id.
    private func route(for node: JSONValue) -> MollyRoute? {
        guard let key = node["key"]?.string else { return nil }
        switch node["type"]?.string {
        case "task": return .task(key)
        case "run": return .run(key)
        default: return nil
        }
    }
}

struct ProvenanceList: View {
    var sources: [JSONValue]

    var body: some View {
        if sources.isEmpty {
            Text("No source recorded").font(.caption).foregroundStyle(.orange)
        } else {
            ForEach(Array(sources.enumerated()), id: \.offset) { _, source in
                Text("Source: \(source["title"]?.string ?? source["key"]?.string ?? "source") · \(source["location"]?.string ?? "no location")\(source["revision"]?.string.map { " @ \($0)" } ?? "")")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .textSelection(.enabled)
            }
        }
    }
}

/// `molly.glossary`: `molly:glossary <project> --json`.
/// Read-only: the CLI has no command that writes glossary terms.
struct MollyGlossaryScreen: View {
    @State private var model = MollyModel.shared

    var body: some View {
        MollyStack {
            if let path = model.projectPath {
                CommandView(["molly:glossary", path, "--json"]) { json, _ in
                    let terms = json["terms"]?.array ?? []
                    Form {
                        Section {
                            FieldRow("Glossary file", json["path"])
                        }
                        Section("Terms") {
                            if terms.isEmpty { Text("No glossary terms yet.").foregroundStyle(.secondary) }
                            ForEach(Array(terms.enumerated()), id: \.offset) { _, entry in
                                VStack(alignment: .leading, spacing: 2) {
                                    HStack {
                                        Text(entry["term"]?.string ?? "term").font(.body.weight(.medium))
                                        Spacer()
                                        Text(entry["origin"]?.string ?? "").font(.caption).foregroundStyle(.secondary)
                                    }
                                    Text(entry["definition"]?.string ?? "").textSelection(.enabled)
                                    if let provenance = entry["provenance"], !provenance.isNull {
                                        Text("Provenance: \(provenance["source"]?.string ?? "?") / \(provenance["method"]?.string ?? "?")")
                                            .font(.caption).foregroundStyle(.secondary)
                                    }
                                    let links = (entry["links"]?.array ?? []).compactMap { link -> String? in
                                        guard let kind = link["kind"]?.string, let ref = link["ref"]?.string else { return nil }
                                        return "\(kind):\(ref)"
                                    }
                                    if !links.isEmpty {
                                        Text("Links: \(links.joined(separator: ", "))").font(.caption).foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                        Section {
                            Text("Molly writes its terms with php artisan molly:journal --project. Add project terms to .molly/GLOSSARY.md outside the Molly section.")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .formStyle(.grouped)
                }
                .navigationTitle("Glossary")
            } else {
                NoProjectView().navigationTitle("Glossary")
            }
        }
    }
}

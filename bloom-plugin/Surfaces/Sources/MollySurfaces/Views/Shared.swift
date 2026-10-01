import AppKit
import Observation
import SwiftUI

/// State every Molly surface shares inside one Bloom window: the selected project and the
/// Artisan host commands run in. Bloom calls each surface factory on every render, so this lives
/// outside the views.
@MainActor
@Observable
final class MollyModel {
    static let shared = MollyModel()

    private static let defaults = UserDefaults(suiteName: "app.sifrious.molly.surfaces")
    private static let selectedKey = "selectedProjectPath"

    let runner = MollyRunner()
    let environment = ProcessInfo.processInfo.environment
    var projects: [MollyProjectIndex.Project] = []
    var indexProblem: String?
    var selectedProjectPath: String? {
        didSet { Self.defaults?.set(selectedProjectPath, forKey: Self.selectedKey) }
    }
    /// Bumped after a write so read screens load again.
    var revision = 0

    private init() {
        selectedProjectPath = Self.defaults?.string(forKey: Self.selectedKey)
        reloadProjects()
    }

    var index: MollyProjectIndex { MollyProjectIndex(environment: environment) }

    var host: String? {
        MollyProjectIndex.resolveHost(selected: selectedProjectPath, environment: environment, projects: projects)
    }

    var selectedProject: MollyProjectIndex.Project? {
        projects.first { $0.path == selectedProjectPath }
    }

    /// The project path commands pass as `--workspace` or a path argument.
    var projectPath: String? { selectedProjectPath ?? host }

    func reloadProjects() {
        let loaded = index.load()
        projects = loaded.projects
        indexProblem = loaded.problem
        if selectedProjectPath == nil { selectedProjectPath = host }
    }

    func artisan(_ arguments: [String], host override: String? = nil) async -> CommandOutcome {
        await runner.artisan(arguments, host: override ?? host)
    }

    func didWrite() { revision += 1 }
}

/// Places a Molly record can link to. Every surface pushes these onto its own stack, so a task
/// leads to its runs, a run to its conversation, diff, and receipts, and back again.
enum MollyRoute: Hashable {
    case task(String)
    case run(String)
    case conversation(String)
    case diff(workspace: String, runID: String)
    case receipts(runID: String, workspace: String?)
}

/// Pulls the id out of Molly's `molly.task:<id>` style link strings.
func mollyLinkID(_ link: String?) -> String? {
    guard let link, let colon = link.firstIndex(of: ":") else { return nil }
    let id = String(link[link.index(after: colon)...])
    return id.isEmpty ? nil : id
}

struct MollyStack<Root: View>: View {
    @State private var path: [MollyRoute] = []
    @ViewBuilder var root: () -> Root

    var body: some View {
        NavigationStack(path: $path) {
            root()
                .navigationDestination(for: MollyRoute.self) { route in
                    switch route {
                    case .task(let id): TaskDetailView(taskID: id)
                    case .run(let id): RunDetailView(runID: id)
                    case .conversation(let id): ConversationDetailView(conversationID: id)
                    case .diff(let workspace, let runID): DiffView(workspace: workspace, runID: runID)
                    case .receipts(let runID, let workspace): ReceiptsView(runID: runID, workspace: workspace)
                    }
                }
        }
    }
}

/// Loads one read command and shows loading, error, and loaded states.
///
/// The error state always names the exact command, the working directory, the exit code, and
/// stderr, so the person can run the same line in Terminal.
struct CommandView<Content: View>: View {
    var arguments: [String]
    var host: String?
    @ViewBuilder var content: (JSONValue, CommandOutcome) -> Content

    @State private var outcome: CommandOutcome?
    @State private var loadedKey: String?
    @State private var attempt = 0
    private var model: MollyModel { .shared }

    init(_ arguments: [String], host: String? = nil, @ViewBuilder content: @escaping (JSONValue, CommandOutcome) -> Content) {
        self.arguments = arguments
        self.host = host
        self.content = content
    }

    private var key: String {
        (arguments + [host ?? model.host ?? "-", String(model.revision), String(attempt)]).joined(separator: "\u{1f}")
    }

    var body: some View {
        Group {
            if let outcome, loadedKey == key {
                if outcome.succeeded, let json = outcome.json {
                    content(json, outcome)
                } else {
                    CommandFailureView(outcome: outcome) { attempt += 1 }
                }
            } else {
                CommandLoadingView(command: "php artisan " + arguments.joined(separator: " "))
            }
        }
        .task(id: key) {
            let result = await model.artisan(arguments, host: host)
            outcome = result
            loadedKey = key
        }
    }
}

struct CommandLoadingView: View {
    var command: String

    var body: some View {
        VStack(spacing: 10) {
            ProgressView()
            Text("Running")
                .font(.headline)
            Text(command)
                .font(.caption.monospaced())
                .foregroundStyle(.secondary)
                .textSelection(.enabled)
                .multilineTextAlignment(.center)
        }
        .padding(24)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

struct CommandFailureView: View {
    var outcome: CommandOutcome
    var retry: (() -> Void)?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Label(outcome.headline, systemImage: symbol)
                    .font(.title3.weight(.semibold))
                    .foregroundStyle(tint)
                if !outcome.detail.isEmpty {
                    Text(outcome.detail)
                        .textSelection(.enabled)
                }
                CommandTranscript(outcome: outcome)
                if let retry {
                    Button("Try Again", action: retry)
                }
            }
            .padding(20)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private var symbol: String {
        if case .commandNotAvailable = outcome.failure { return "puzzlepiece.extension" }
        return "exclamationmark.triangle"
    }

    private var tint: Color {
        if case .commandNotAvailable = outcome.failure { return .secondary }
        return .red
    }
}

/// The command line, working directory, exit code, stdout, and stderr of one process.
struct CommandTranscript: View {
    var outcome: CommandOutcome

    var body: some View {
        GroupBox {
            VStack(alignment: .leading, spacing: 8) {
                LabeledContent("Command") {
                    Text(outcome.invocation.display)
                        .font(.caption.monospaced())
                        .textSelection(.enabled)
                }
                LabeledContent("Exit code", value: outcome.exitCode < 0 ? "not started" : String(outcome.exitCode))
                if !outcome.stderr.isEmpty {
                    OutputBlock(title: "stderr", text: outcome.stderr)
                }
                if !outcome.stdout.isEmpty, !outcome.succeeded {
                    OutputBlock(title: "stdout", text: outcome.stdout)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

struct OutputBlock: View {
    var title: String
    var text: String

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title).font(.caption.weight(.semibold)).foregroundStyle(.secondary)
            ScrollView([.vertical, .horizontal]) {
                Text(text)
                    .font(.caption.monospaced())
                    .textSelection(.enabled)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(maxHeight: 220)
            .padding(6)
            .background(.quaternary.opacity(0.4), in: RoundedRectangle(cornerRadius: 6))
        }
    }
}

/// Collapsed raw JSON for fields a screen does not lay out yet.
struct RawJSONDisclosure: View {
    var title = "Raw JSON"
    var json: JSONValue

    var body: some View {
        DisclosureGroup(title) {
            OutputBlock(title: "", text: json.pretty)
        }
    }
}

/// Runs a write command from a button and reports the outcome in place.
@MainActor
@Observable
final class ActionState {
    var running: String?
    var last: CommandOutcome?

    func run(_ arguments: [String], host: String? = nil, onSuccess: @MainActor (CommandOutcome) -> Void = { _ in }) async {
        running = "php artisan " + arguments.joined(separator: " ")
        let outcome = await MollyModel.shared.artisan(arguments, host: host)
        running = nil
        last = outcome
        if outcome.succeeded {
            MollyModel.shared.didWrite()
            onSuccess(outcome)
        }
    }
}

struct ActionStatusView: View {
    var state: ActionState

    var body: some View {
        if let running = state.running {
            HStack(spacing: 8) {
                ProgressView().controlSize(.small)
                Text(running).font(.caption.monospaced()).textSelection(.enabled)
            }
        } else if let last = state.last {
            if last.succeeded {
                VStack(alignment: .leading, spacing: 4) {
                    Label("\(last.invocation.arguments.first { $0.hasPrefix("molly:") } ?? "Command") exited \(last.exitCode)", systemImage: "checkmark.circle")
                        .foregroundStyle(.secondary)
                    if let json = last.json { RawJSONDisclosure(title: "Command output", json: json) }
                }
            } else {
                CommandFailureView(outcome: last)
            }
        }
    }
}

struct FieldRow: View {
    var label: String
    var value: JSONValue?

    init(_ label: String, _ value: JSONValue?) {
        self.label = label
        self.value = value
    }

    init(_ label: String, text: String?) {
        self.label = label
        self.value = text.map(JSONValue.string)
    }

    var body: some View {
        LabeledContent(label) {
            Text(value.map { $0.isNull ? "Not reported" : $0.display } ?? "Not reported")
                .foregroundStyle(value == nil || value?.isNull == true ? .secondary : .primary)
                .textSelection(.enabled)
                .multilineTextAlignment(.trailing)
        }
    }
}

struct StatusBadge: View {
    var status: String?

    var body: some View {
        Text(status ?? "unknown")
            .font(.caption.weight(.medium))
            .padding(.horizontal, 6)
            .padding(.vertical, 2)
            .background(color.opacity(0.15), in: Capsule())
            .foregroundStyle(color)
    }

    /// Only an explicit pass reads as green. Skipped, not run, and unknown stay neutral.
    private var color: Color {
        switch status?.lowercased() {
        case "completed", "passed", "pass", "ready", "ok", "approved", "running_ok": return .green
        case "failed", "fail", "error", "blocked": return .red
        case "running", "pending", "queued", "claimed": return .orange
        default: return .secondary
        }
    }
}

struct NoProjectView: View {
    var body: some View {
        ContentUnavailableView {
            Label("No Molly project selected", systemImage: "folder.badge.questionmark")
        } description: {
            Text("Open the Molly page to pick a project, create one, or add an existing Laravel app.")
        }
    }
}

enum MollyPanels {
    @MainActor
    static func chooseFolder(prompt: String) -> String? {
        let panel = NSOpenPanel()
        panel.canChooseDirectories = true
        panel.canChooseFiles = false
        panel.canCreateDirectories = true
        panel.allowsMultipleSelection = false
        panel.prompt = prompt
        return panel.runModal() == .OK ? panel.url?.path : nil
    }
}

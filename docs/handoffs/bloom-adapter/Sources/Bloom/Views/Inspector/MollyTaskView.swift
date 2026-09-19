import SwiftUI
import BloomCore

/// A one-line reminder that this workspace has a Molly task, shown on every other tab.
///
/// The full contract lives on the Molly tab. This band exists so a reader sitting on
/// Changes still sees that Create pull request is waiting on approval, without inventing
/// a second strip.
struct MollyStatusBand: View {
    let snapshot: MollyTaskSnapshot

    var body: some View {
        HStack(spacing: InspectorLayout.gap) {
            Image(systemName: "checkmark.seal")
                .font(Typo.micro)
                .foregroundStyle(Palette.accent)
                .accessibilityHidden(true)
            Text(snapshot.summary)
                .font(Typo.captionEmphasis)
                .foregroundStyle(Palette.textPrimary)
                .lineLimit(1)
                .truncationMode(.middle)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, InspectorLayout.inset)
        .frame(height: InspectorLayout.barHeight)
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Molly task. \(snapshot.summary)")
    }
}

/// The inspector pane for a Molly task bound to this workspace.
///
/// Bloom already shows the diff and the pull request. This pane is the contract: what
/// may be edited, which Pest file is locked, what the verifiers said, and the approval
/// that has to happen before the strip opens a pull request.
struct MollyTaskView: View {
    let model: WorkspaceModel

    @State private var notice: String?

    var body: some View {
        if let snapshot = model.mollyTask {
            ScrollView {
                VStack(alignment: .leading, spacing: InspectorLayout.gap) {
                    header(snapshot)
                    paths(snapshot)
                    outcomes(snapshot)
                    if snapshot.status == .awaitingApproval {
                        approveButton
                    }
                    if let notice {
                        Text(notice)
                            .font(Typo.caption)
                            .foregroundStyle(Palette.negative)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                .padding(.horizontal, InspectorLayout.inset)
                .padding(.vertical, Metrics.spacing)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        } else {
            EmptyStateView(
                glyph: "checkmark.seal",
                title: "No Molly task",
                message: "Bind a Molly contract to this workspace. Molly does not create another worktree."
            )
        }
    }

    private func header(_ snapshot: MollyTaskSnapshot) -> some View {
        VStack(alignment: .leading, spacing: InspectorLayout.tight) {
            Text(snapshot.status.headline)
                .font(Typo.heading)
                .foregroundStyle(Palette.textPrimary)
            Text(snapshot.contract.request)
                .font(Typo.body)
                .foregroundStyle(Palette.textSecondary)
                .fixedSize(horizontal: false, vertical: true)
            if let issue = snapshot.issueURL {
                Text(issue)
                    .font(Typo.caption)
                    .foregroundStyle(Palette.textTertiary)
                    .lineLimit(1)
                    .truncationMode(.middle)
            }
            if let url = snapshot.pullRequestURL {
                Text(url)
                    .font(Typo.caption)
                    .foregroundStyle(Palette.textTertiary)
                    .lineLimit(1)
                    .truncationMode(.middle)
            }
        }
    }

    private func paths(_ snapshot: MollyTaskSnapshot) -> some View {
        VStack(alignment: .leading, spacing: InspectorLayout.tight) {
            Text("Allowed files")
                .font(Typo.captionEmphasis)
                .foregroundStyle(Palette.textSecondary)
            ForEach(snapshot.contract.allowedWritePaths, id: \.self) { path in
                Text(path)
                    .font(Typo.caption)
                    .foregroundStyle(Palette.textPrimary)
                    .lineLimit(1)
                    .truncationMode(.middle)
            }
            if let test = snapshot.protectedTest {
                Text("Protected test")
                    .font(Typo.captionEmphasis)
                    .foregroundStyle(Palette.textSecondary)
                    .padding(.top, InspectorLayout.tight)
                Text(test.path)
                    .font(Typo.caption)
                    .foregroundStyle(Palette.textPrimary)
                    .lineLimit(1)
                    .truncationMode(.middle)
            }
        }
    }

    @ViewBuilder
    private func outcomes(_ snapshot: MollyTaskSnapshot) -> some View {
        if !snapshot.outcomes.isEmpty {
            VStack(alignment: .leading, spacing: InspectorLayout.tight) {
                Text("Verifiers")
                    .font(Typo.captionEmphasis)
                    .foregroundStyle(Palette.textSecondary)
                ForEach(snapshot.outcomes, id: \.verifier) { outcome in
                    HStack(spacing: InspectorLayout.gap) {
                        Text(outcome.verifier)
                            .font(Typo.caption)
                            .foregroundStyle(Palette.textPrimary)
                        Spacer(minLength: 0)
                        Text(outcome.state)
                            .font(Typo.micro)
                            .foregroundStyle(outcome.passed ? Palette.positive : Palette.textSecondary)
                    }
                }
            }
        }
    }

    private var approveButton: some View {
        Button("Approve task") {
            do {
                try model.approveMolly()
                notice = nil
            } catch {
                notice = String(describing: error)
            }
        }
        .buttonStyle(.borderedProminent)
        .buttonBorderShape(.roundedRectangle(radius: Metrics.corner))
        .tint(Palette.controlAccent)
        .controlSize(.regular)
        .help("Record human approval. Bloom then opens the pull request from the strip above.")
    }
}

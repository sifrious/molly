import SwiftUI

/// Hosted Molly screens — domain work stays in the Laravel consumer; Bloom only hosts chrome.
struct MollyHomeScreen: View {
    var body: some View {
        MollyScreenChrome(
            title: "Molly",
            systemImage: "leaf",
            blurb: "Molly is available in Bloom through the plugin host. Use Terminal for molly:demo / molly:start, then inspect tasks and runs from the Molly web UI or later Bloom surfaces."
        )
    }
}

struct MollyTasksScreen: View {
    var body: some View {
        MollyScreenChrome(
            title: "Tasks",
            systemImage: "checklist",
            blurb: "Saved Molly tasks live in the consumer app database. Open the local Molly UI (MOLLY_UI_ENABLED) or run php artisan molly:task NAME."
        )
    }
}

struct MollyConversationsScreen: View {
    var body: some View {
        MollyScreenChrome(
            title: "Conversations",
            systemImage: "bubble.left.and.bubble.right",
            blurb: "Amp / agent conversations link through Molly MCP later. This surface confirms the Bloom nav id hosts a real screen."
        )
    }
}

struct MollySettingsScreen: View {
    var body: some View {
        MollyScreenChrome(
            title: "Molly Settings",
            systemImage: "gearshape",
            blurb: "Agent and model settings stay in the Laravel app (molly:setup / config). Bloom hosts this settings entry without owning Molly config."
        )
    }
}

private struct MollyScreenChrome: View {
    let title: String
    let systemImage: String
    let blurb: String

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Label(title, systemImage: systemImage)
                .font(.largeTitle.weight(.semibold))
            Text(blurb)
                .foregroundStyle(.secondary)
                .frame(maxWidth: 520, alignment: .leading)
            Text("Plugin id sifrious.molly — surface factories ship with the Molly bloom-plugin drop.")
                .font(.caption)
                .foregroundStyle(.tertiary)
        }
        .padding(24)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }
}

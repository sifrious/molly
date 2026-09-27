import BloomPluginAPI
import os
import SwiftUI

/// Registers Molly's centre-column surfaces with Bloom's generic plugin loader.
///
/// Named in Surfaces.bundle Info.plist as `MollySurfaceProvider` (@objc). Each nav id matches an
/// entry in `bloom-plugin/plugin.json`.
@objc(MollySurfaceProvider)
public final class MollySurfaceProvider: PluginSurfaceProvider {
    static let logger = Logger(subsystem: "app.sifrious.molly.surfaces", category: "plugin")

    @MainActor
    static let surfaces: [(String, @MainActor () -> AnyView)] = [
        ("molly.home", { AnyView(MollyHomeScreen()) }),
        ("molly.tasks", { AnyView(MollyTasksScreen()) }),
        ("molly.runs", { AnyView(MollyRunsScreen()) }),
        ("molly.conversations", { AnyView(MollyConversationsScreen()) }),
        ("molly.graph", { AnyView(MollyGraphScreen()) }),
        ("molly.glossary", { AnyView(MollyGlossaryScreen()) }),
        ("molly.settings", { AnyView(MollySettingsScreen()) }),
        ("molly.worker", { AnyView(MollyWorkerScreen()) }),
    ]

    @MainActor
    public override class func registerSurfaces(
        pluginID: String,
        register: (String, @escaping @MainActor () -> AnyView) -> Void
    ) {
        for (navID, factory) in surfaces {
            register(navID, factory)
        }
        let ids = surfaces.map(\.0).joined(separator: ",")
        logger.notice("Registered \(surfaces.count) Molly surfaces for \(pluginID, privacy: .public): \(ids, privacy: .public)")
    }
}

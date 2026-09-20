import SwiftUI
import BloomPluginAPI

/// Registers Molly centre-column factories with Bloom's generic surface loader.
///
/// Named in Surfaces.bundle Info.plist as `MollySurfaceProvider` (@objc).
@objc(MollySurfaceProvider)
public final class MollySurfaceProvider: PluginSurfaceProvider {
    @MainActor
    public override class func registerSurfaces(
        pluginID: String,
        register: (String, @escaping @MainActor () -> AnyView) -> Void
    ) {
        register("molly.home") { AnyView(MollyHomeScreen()) }
        register("molly.tasks") { AnyView(MollyTasksScreen()) }
        register("molly.conversations") { AnyView(MollyConversationsScreen()) }
        register("molly.settings") { AnyView(MollySettingsScreen()) }
    }
}

# MME-5297 — Bloom plugin host + Molly registration

## Branches

- Bloom (local Spatie checkout): `tip/mme-5297-plugin-host` under `/Users/mme/gits/sifrious/bloom-spatie` @ `e03dd138` + tip commits
- Molly: `tip/mme-5297-plugin-seam` under `/Users/mme/gits/sifrious/molly-worktrees/mme-5297-plugin-seam`

## Discovery

1. Bloom reads `~/Library/Application Support/Bloom/Plugins/<id>/plugin.json`
2. Enablement is `~/Library/Application Support/Bloom/Plugins/enabled.json` (JSON string array)
3. Unknown `apiVersion` → skip + log; never crash
4. Sidebar renders `PluginRegistry.enabledNavItems`; detail hosts `PluginHostView`
5. SwiftUI factories register at launch via `PluginSurfaceRegistry` (compile-time until dynamic loading exists)

## Molly registration

- Manifest: `bloom-plugin/plugin.json` (`sifrious.molly` / `molly.home`)
- Helper: `bin/molly-bloom-plugin-register`
- Bloom tip also seeds + enables the manifest on first `installPlugins()` so enable/disable is proveable without a separate installer UI

## Proof

- Enable → Molly nav + home screen
- Disable / remove manifest → surface gone
- `Tools/test-core.sh PluginRegistry` on BloomCore

## Non-goals (later tips)

5298 projects, 5299 graph, 5300 settings, 5301 glossary, 5303 tasks/runs.

## Push / Spatie

No Molly push and no Spatie push unless Mary / bitty / owner say. Upstream of the generic host is Manual later.

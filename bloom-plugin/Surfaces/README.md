# MollySurfaces

SwiftUI centre-column factories for Bloom nav ids `molly.home`, `molly.tasks`,
`molly.conversations`, and `molly.settings`.

Build against a Bloom tip that includes `BloomPluginAPI` (MME-5353 Phase 1):

```bash
export BLOOM_ROOT=/path/to/sifrious/bloom   # tip with BloomPluginAPI
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
cd bloom-plugin/Surfaces && ./build-bundle.sh
```

Output: `bloom-plugin/Surfaces.bundle` (committed for consumers). The register
script installs it beside `plugin.json` under Bloom Application Support.

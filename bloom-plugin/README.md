# Molly Bloom plugin

This folder is the plugin Bloom loads through its drop-folder seam. Bloom reads `plugin.json` and loads `Surfaces.bundle`; Molly never patches the Bloom app.

- `plugin.json` declares id `sifrious.molly`, version `0.2.0`, `apiVersion` 1, and eight nav ids: `molly.home`, `molly.tasks`, `molly.runs`, `molly.conversations`, `molly.graph`, `molly.glossary`, `molly.settings`, and `molly.worker`. The `bloom` key records the Bloom commit the bundle was built against. Bloom's `PluginManifest` decoder ignores that key.
- `Surfaces.bundle` holds the SwiftUI screens. Its Info.plist names `MollySurfaceProvider` under `BloomPluginSurfaceProvider` and records `MollyBuiltAgainstBloomCommit`.
- `Surfaces/` holds the Swift sources, `build-bundle.sh`, and the core tests.

Build and install:

```bash
export BLOOM_ROOT=/path/to/bloom   # checkout with BloomPluginAPI, e.g. commit 1599f05f
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
cd -
./bloom-plugin/Surfaces/build-bundle.sh
./bin/molly-bloom-plugin-register
```

When the module was built with `--scratch-path`, set `BLOOM_PRODUCTS` to the folder that holds `BloomPluginAPI.swiftmodule`.

The compiled bundle is not in the Composer package archive. Build it before running `bin/molly-bloom-plugin-register`.

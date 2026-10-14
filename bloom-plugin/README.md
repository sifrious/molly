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

`build-bundle.sh` requires `BLOOM_ROOT` and exits with a message when it is not set. The checkout has to be Bloom commit `1599f05f` with clean `BloomPluginAPI` sources. Set `MOLLY_BLOOM_COMMIT` only when you mean to link a different BloomPluginAPI. When the module was built with `--scratch-path`, also set `BLOOM_PRODUCTS` to the folder that holds `BloomPluginAPI.swiftmodule`.

The script remaps source paths and sets `SOURCE_DATE_EPOCH` and `ZERO_AR_DATE`. It keeps the Mach-O `LC_UUID`, because dyld will not load a dylib without one. Two builds on the same toolchain can differ by that UUID. It records `MollySourcesDigest` in the bundle's Info.plist. `bin/molly-bloom-plugin-register` refuses a bundle with no `LC_UUID`, a bundle that does not contain `molly:host-observe`, and a bundle whose recorded digest does not match the Swift sources.

The compiled bundle is not in the Composer package archive. Build it and commit `bloom-plugin/Surfaces.bundle` before running `bin/molly-bloom-plugin-register`.

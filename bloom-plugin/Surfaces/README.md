# MollySurfaces

SwiftUI screens for Molly's Bloom nav ids. Each screen runs `php artisan molly:… --json` in the selected Laravel app and renders the JSON. Molly's actions own every decision; the screens only dispatch and present.

`Sources/MollySurfaces/Core` is Foundation only: JSON reading, PHP lookup, project index and Artisan host resolution, and the process runner. `Sources/MollySurfaces/Views` holds the screens.

Build against a Bloom checkout that includes `BloomPluginAPI`:

```bash
export BLOOM_ROOT=/path/to/bloom
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
cd - && ./bloom-plugin/Surfaces/build-bundle.sh
```

The script compiles every Swift file under `Sources/MollySurfaces` for `arm64-apple-macos26.0`, writes `bloom-plugin/Surfaces.bundle`, and prints the dylib's SHA-256. Override the module folder with `BLOOM_PRODUCTS` and the target with `MOLLY_SURFACES_TARGET`.

Run the core tests without Bloom or PHP:

```bash
./bloom-plugin/Surfaces/Tests/run-tests.sh
```

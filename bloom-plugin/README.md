# Molly Bloom plugin drop

- `plugin.json` — manifest (id `sifrious.molly`, nav ids `molly.home|tasks|conversations|settings`)
- `Surfaces.bundle` — SwiftUI factories implementing Bloom `PluginSurfaceProviding`
  (Info.plist `BloomPluginSurfaceProvider` = `MollySurfaceProvider`)

Install into Bloom Application Support:

```bash
./bin/molly-bloom-plugin-register
```

Rebuild the committed bundle after BloomPluginAPI API changes:

```bash
export BLOOM_ROOT=/path/to/sifrious/bloom   # tip with BloomPluginAPI
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
./bloom-plugin/Surfaces/build-bundle.sh
```

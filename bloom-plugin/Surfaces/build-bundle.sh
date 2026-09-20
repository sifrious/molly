#!/usr/bin/env bash
# Build MollySurfaces dylib against a Bloom tip BloomPluginAPI module; pack Surfaces.bundle.
# Requires: BLOOM_ROOT with `swift build -c release --target BloomPluginAPI` already done.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT_BUNDLE="${1:-$ROOT/../Surfaces.bundle}"
BLOOM_ROOT="${BLOOM_ROOT:-/Users/mme/gits/sifrious/bloom-worktrees/mme-5353-loader}"
PRODUCTS="${BLOOM_PRODUCTS:-$BLOOM_ROOT/.build/out/Products/Release}"
SRC="$ROOT/Sources/MollySurfaces"
BUILD_DIR="$ROOT/.build-direct"
mkdir -p "$BUILD_DIR"

export SDKROOT="${SDKROOT:-$(xcrun --sdk macosx --show-sdk-path)}"

if [[ ! -d "$PRODUCTS/BloomPluginAPI.swiftmodule" ]]; then
  echo "Missing BloomPluginAPI.swiftmodule under $PRODUCTS — build Bloom tip first:" >&2
  echo "  cd \$BLOOM_ROOT && swift build -c release --target BloomPluginAPI" >&2
  exit 1
fi

xcrun swiftc -emit-library \
  -o "$BUILD_DIR/MollySurfaces" \
  -module-name MollySurfaces \
  -parse-as-library \
  -sdk "$SDKROOT" \
  -swift-version 6 \
  -I "$PRODUCTS" \
  -Xlinker -undefined -Xlinker dynamic_lookup \
  "$SRC/MollySurfaceProvider.swift" \
  "$SRC/Views/MollyScreens.swift"

file "$BUILD_DIR/MollySurfaces"
otool -L "$BUILD_DIR/MollySurfaces" || true

rm -rf "$OUT_BUNDLE"
mkdir -p "$OUT_BUNDLE/Contents/MacOS"
cp "$BUILD_DIR/MollySurfaces" "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"
chmod +x "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"
install_name_tool -id "@loader_path/MollySurfaces" "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"

cat > "$OUT_BUNDLE/Contents/Info.plist" <<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleIdentifier</key>
  <string>app.sifrious.molly.surfaces</string>
  <key>CFBundleName</key>
  <string>MollySurfaces</string>
  <key>CFBundlePackageType</key>
  <string>BNDL</string>
  <key>CFBundleExecutable</key>
  <string>MollySurfaces</string>
  <key>CFBundleShortVersionString</key>
  <string>0.1.0</string>
  <key>BloomPluginSurfaceProvider</key>
  <string>MollySurfaceProvider</string>
</dict>
</plist>
PLIST

echo "Wrote $OUT_BUNDLE"
ls -laR "$OUT_BUNDLE"

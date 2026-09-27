#!/usr/bin/env bash
# Build the MollySurfaces dylib against a Bloom checkout's BloomPluginAPI module and pack
# Surfaces.bundle.
#
# Requires BLOOM_ROOT, the Bloom checkout, with BloomPluginAPI built first, for example:
#   cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
# Set BLOOM_PRODUCTS when the module lives elsewhere (a --scratch-path build or Xcode output).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT_BUNDLE="${1:-$ROOT/../Surfaces.bundle}"
if [[ -z "${BLOOM_ROOT:-}" ]]; then
  echo "Set BLOOM_ROOT to the Bloom checkout that holds BloomPluginAPI, for example:" >&2
  echo "  BLOOM_ROOT=/path/to/bloom $0" >&2
  exit 1
fi
SRC="$ROOT/Sources/MollySurfaces"
BUILD_DIR="$ROOT/.build-direct"
VERSION="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$ROOT/../plugin.json")"
TARGET="${MOLLY_SURFACES_TARGET:-arm64-apple-macos26.0}"

if [[ -z "${BLOOM_PRODUCTS:-}" ]]; then
  for candidate in "$BLOOM_ROOT/.build/release" "$BLOOM_ROOT/.build/out/Products/Release"; do
    if [[ -e "$candidate/BloomPluginAPI.swiftmodule" ]]; then
      BLOOM_PRODUCTS="$candidate"
      break
    fi
  done
fi

if [[ -z "${BLOOM_PRODUCTS:-}" || ! -e "$BLOOM_PRODUCTS/BloomPluginAPI.swiftmodule" ]]; then
  echo "Missing BloomPluginAPI.swiftmodule. Build it in the Bloom checkout first:" >&2
  echo "  cd \"$BLOOM_ROOT\" && swift build -c release --target BloomPluginAPI" >&2
  echo "or set BLOOM_PRODUCTS to the directory that holds BloomPluginAPI.swiftmodule." >&2
  exit 1
fi

BLOOM_COMMIT="$(git -C "$BLOOM_ROOT" rev-parse --short=8 HEAD 2>/dev/null || echo unknown)"
if [[ "$BLOOM_COMMIT" != "unknown" && -n "$(git -C "$BLOOM_ROOT" status --porcelain -- Sources/BloomPluginAPI Sources/BloomCore 2>/dev/null)" ]]; then
  BLOOM_COMMIT="$BLOOM_COMMIT-dirty"
fi

export SDKROOT="${SDKROOT:-$(xcrun --sdk macosx --show-sdk-path)}"
mkdir -p "$BUILD_DIR"

SOURCES=()
while IFS= read -r file; do SOURCES+=("$file"); done < <(find "$SRC" -name '*.swift' | sort)

echo "Building MollySurfaces $VERSION against Bloom $BLOOM_COMMIT ($BLOOM_PRODUCTS)"
xcrun swiftc -emit-library \
  -o "$BUILD_DIR/MollySurfaces" \
  -module-name MollySurfaces \
  -parse-as-library \
  -target "$TARGET" \
  -sdk "$SDKROOT" \
  -swift-version 6 \
  -O \
  -I "$BLOOM_PRODUCTS" \
  -Xlinker -undefined -Xlinker dynamic_lookup \
  "${SOURCES[@]}"

rm -rf "$OUT_BUNDLE"
mkdir -p "$OUT_BUNDLE/Contents/MacOS"
cp "$BUILD_DIR/MollySurfaces" "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"
chmod +x "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"
install_name_tool -id "@loader_path/MollySurfaces" "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"

cat > "$OUT_BUNDLE/Contents/Info.plist" <<PLIST
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
  <string>$VERSION</string>
  <key>BloomPluginSurfaceProvider</key>
  <string>MollySurfaceProvider</string>
  <key>MollyBuiltAgainstBloomCommit</key>
  <string>$BLOOM_COMMIT</string>
  <key>MollyBloomPluginAPIVersion</key>
  <integer>1</integer>
</dict>
</plist>
PLIST

echo "Wrote $OUT_BUNDLE"
file "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"
shasum -a 256 "$OUT_BUNDLE/Contents/MacOS/MollySurfaces"

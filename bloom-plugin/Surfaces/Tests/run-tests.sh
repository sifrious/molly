#!/usr/bin/env bash
# Compile the Foundation-only plugin core with Tests/main.swift and run the checks.
# Needs no Bloom checkout and no PHP.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/.build-direct/molly-surfaces-core-tests"
mkdir -p "$(dirname "$OUT")"
xcrun swiftc -swift-version 6 -o "$OUT" \
  "$ROOT"/Sources/MollySurfaces/Core/*.swift \
  "$ROOT"/Tests/main.swift
"$OUT"

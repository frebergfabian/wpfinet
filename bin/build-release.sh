#!/usr/bin/env bash
# Builds dist/finet-troubleshoot-<version>.zip (top-level folder finet-troubleshoot/, same identity as 2.x).
# Contains only runtime files + built assets (no node_modules, tests or dev tooling).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
node "$ROOT/bin/build-assets.mjs" >/dev/null
VERSION=$(php -r 'preg_match("/Version:\s*([0-9.]+)/", file_get_contents($argv[1]), $m); echo $m[1];' "$ROOT/finet-troubleshoot/finet-troubleshoot.php")
CONST=$(php -r 'preg_match("/FINET_TS_VERSION\x27, \x27([0-9.]+)/", file_get_contents($argv[1]), $m); echo $m[1];' "$ROOT/finet-troubleshoot/finet-troubleshoot.php")
[[ "$VERSION" == "$CONST" ]] || { echo "Version mismatch: header $VERSION vs constant $CONST" >&2; exit 1; }
grep -q "Stable tag: $VERSION" "$ROOT/finet-troubleshoot/readme.txt" || { echo "readme.txt Stable tag is not $VERSION" >&2; exit 1; }
mkdir -p "$ROOT/dist"; STAGE=$(mktemp -d); cp -r "$ROOT/finet-troubleshoot" "$STAGE/finet-troubleshoot"
find "$STAGE/finet-troubleshoot" \( -name '.DS_Store' -o -name '*.log' -o -name '.git*' \) -exec rm -rf {} + 2>/dev/null || true
OUT="$ROOT/dist/finet-troubleshoot-$VERSION.zip"; rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" finet-troubleshoot)
rm -rf "$STAGE"
( cd "$ROOT/dist" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )
echo "$OUT ($(du -h "$OUT" | cut -f1)), sha256 $(cut -d' ' -f1 "$OUT.sha256")"

#!/usr/bin/env bash
# Full local check: PHP lint, PHPUnit, JS units. Fails on the first failing step (no pipes hiding exit codes).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
"$ROOT/bin/php-lint.sh"
node "$ROOT/bin/build-assets.mjs"
"$ROOT/bin/test.sh"
node --test "$ROOT"/tests/js/*.test.js

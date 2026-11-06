#!/usr/bin/env bash
# Guide/banner layout and CLS inside the real Elementor structure of dev.finet.se (offline replay, see tests/e2e/elementor/).
# Usage: tests/e2e/run-elementor-layout.sh [--baseline] [--shots <dir>]
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT" && node tests/e2e/elementor/layout.mjs "$@"

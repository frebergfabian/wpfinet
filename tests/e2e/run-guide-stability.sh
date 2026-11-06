#!/usr/bin/env bash
# Usage: tests/e2e/run-guide-stability.sh [guide-import.json]
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; WP="php $ROOT/.test-env/wp-cli.phar --path=$ROOT/.test-env/wordpress --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$ROOT/.test-env/wordpress/wp-content/database/test.sqlite"
SEED=$(GUIDE_JSON="${1:-}" $WP eval-file "$ROOT/tests/e2e/guide-seed.php" 2>/dev/null | tail -1)
URL=$(echo "$SEED" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["url"];')
"$ROOT/bin/serve-test-site.sh" >/dev/null
GUIDE_URL="$URL" SHOTS="${SHOTS:-$ROOT/.test-env/guide-shots}" node "$ROOT/tests/e2e/guide-stability.mjs"; CODE=$?
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null; exit $CODE

#!/usr/bin/env bash
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WPD="$ROOT/.test-env/wordpress"; WP="php $ROOT/.test-env/wp-cli.phar --path=$WPD --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"; : > "$WPD/wp-content/debug.log"
SEED=$($WP eval-file "$ROOT/tests/e2e/seed.php" 2>/dev/null | tail -1)
$WP eval-file "$ROOT/tests/e2e/ui-seed.php" 2>&1 | tail -1
"$ROOT/bin/serve-test-site.sh" >/dev/null
SEED="$SEED" SHOTS="${SHOTS:-$ROOT/.test-env/ui-shots}" node "$ROOT/tests/e2e/ui-review.mjs"; CODE=$?
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null
echo "--- debug.log (plugin):"; grep -E "finet-troubleshoot" "$WPD/wp-content/debug.log" | sort | uniq -c | sort -rn | head -20
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"
exit $CODE

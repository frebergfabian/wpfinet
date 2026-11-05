#!/usr/bin/env bash
# Seeds the site, serves it and runs the browser E2E. Usage: tests/e2e/run-browser.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP="php $ROOT/.test-env/wp-cli.phar --path=$ROOT/.test-env/wordpress --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$ROOT/.test-env/wordpress/wp-content/database/test.sqlite"
SEED=$($WP eval-file "$ROOT/tests/e2e/seed.php" 2>/dev/null | tail -1)
GUIDE_ID=$(echo "$SEED" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["guide"];')
"$ROOT/bin/serve-test-site.sh" >/dev/null
GUIDE_ID=$GUIDE_ID node "$ROOT/tests/e2e/browser.mjs"; CODE=$?
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null || true
exit $CODE

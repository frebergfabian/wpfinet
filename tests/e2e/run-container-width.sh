#!/usr/bin/env bash
# Guide width in many container types (flex/grid/inline-block/float/table) at 1920–375 px.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"; WP="php $ROOT/.test-env/wp-cli.phar --path=$ROOT/.test-env/wordpress --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$ROOT/.test-env/wordpress/wp-content/database/test.sqlite"
URL=$($WP eval-file "$ROOT/tests/e2e/container-width-seed.php" 2>/dev/null | tail -1 | php -r 'echo json_decode(stream_get_contents(STDIN),true)["url"];')
"$ROOT/bin/serve-test-site.sh" >/dev/null
PAGE_URL="$URL" node "$ROOT/tests/e2e/container-width.mjs"; CODE=$?
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null; exit $CODE

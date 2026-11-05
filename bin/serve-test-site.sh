#!/usr/bin/env bash
# Starts the test site on 127.0.0.1:8890 (background). Stop with: kill $(cat .test-env/server.pid)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$ROOT/.test-env/server.pid" ]] && kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null || true
cd "$ROOT/.test-env/wordpress"
PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:8890 -t . "$ROOT/tests/e2e/router.php" > "$ROOT/.test-env/server.log" 2>&1 &
echo $! > "$ROOT/.test-env/server.pid"
for i in $(seq 1 40); do curl -s -o /dev/null http://127.0.0.1:8890/ && break; sleep 0.25; done
echo "Serving http://127.0.0.1:8890 (pid $(cat "$ROOT/.test-env/server.pid"))"

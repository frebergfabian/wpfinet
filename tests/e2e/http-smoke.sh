#!/usr/bin/env bash
# HTTP smoke + TTFB test against a real web server (php -S). Usage: tests/e2e/http-smoke.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP="php $ROOT/.test-env/wp-cli.phar --path=$ROOT/.test-env/wordpress --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$ROOT/.test-env/wordpress/wp-content/database/test.sqlite"
SEED=$($WP eval-file "$ROOT/tests/e2e/seed.php" 2>/dev/null | tail -1)
"$ROOT/bin/serve-test-site.sh" >/dev/null
B=http://127.0.0.1:8890
OUT=$(echo "$SEED" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["outage_url"];')
FAIL=0
check() { # url expected_code [must_contain] [must_not_contain]
  local body code; body=$(curl -s -w '\n%{http_code}' "$1"); code=${body##*$'\n'}; body=${body%$'\n'*}
  local ok=1; [[ "$code" == "$2" ]] || ok=0
  [[ -z "${3:-}" || "$body" == *"$3"* ]] || ok=0
  [[ -z "${4:-}" || "$body" != *"$4"* ]] || ok=0
  if [[ $ok == 1 ]]; then echo "PASS $code $1 ${3:+[has '$3']}"; else echo "FAIL $code (want $2) $1 ${3:+[want '$3']} ${4:+[forbid '$4']}"; FAIL=1; fi
}
check "$B/" 200 "data-wpfinet-banner"
check "$B/driftstorningar/" 200 "E2E fiberavbrott"
check "$OUT" 200 "Felsökning pågår" "INTERN-HEMLIGHET"
check "$B/driftstorningar/e2e-fiberavbrott/" 200 "E2E fiberavbrott"
check "$B/felsokning/" 200 "finet-troubleshoot__data"
check "$B/felsokning/" 200 "wpfinet-outage-status is-affected"
check "$B/wp-json/wpfinet/v1/outages/current" 200 "E2E fiberavbrott" "INTERN-HEMLIGHET"
check "$B/wp-json/finet-troubleshoot/v1/guides" 401
check "$B/wp-json/wpfinet/v1/analytics/summary" 401
check "$B/?rest_route=/wpfinet/v1/outages" 200
for u in "$B/" "$B/felsokning/" "$B/driftstorningar/"; do
  T=$(for i in 1 2 3 4 5 6 7; do curl -s -o /dev/null -w '%{time_starttransfer}\n' "$u"; done | sort -n | sed -n 4p)
  echo "TTFB median $u ${T}s"
done
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null || true
exit $FAIL

#!/usr/bin/env bash
# Real upgrade test: install the original 2.0.1 ZIP, create data, upgrade in place with the 3.0 ZIP, compare,
# then roll back to 2.0.1. Usage: tests/upgrade/run-upgrade-test.sh /path/to/finet-troubleshoot-2.0.1.zip
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OLD_ZIP="${1:?path to finet-troubleshoot-2.0.1.zip}"
NEW_ZIP="$("$ROOT/bin/build-release.sh" | cut -d' ' -f1)"
WPD="$ROOT/.test-env/wordpress"
WP="php $ROOT/.test-env/wp-cli.phar --path=$WPD --allow-root"
OUT="$ROOT/.test-env/upgrade"; mkdir -p "$OUT"
FAIL=0
pass() { echo "PASS $1"; }
fail() { echo "FAIL $1"; FAIL=1; }
compare() { php -r '
$a = json_decode(file_get_contents($argv[1]), true); $b = json_decode(file_get_contents($argv[2]), true); $ok = true;
foreach (explode(",", $argv[3]) as $k) { if ($a[$k] !== $b[$k]) { $ok = false; echo "FAIL $argv[4]: $k changed\n"; } else { echo "PASS $argv[4]: $k unchanged (" . (is_array($a[$k]) ? count($a[$k]) : 1) . ")\n"; } }
exit($ok ? 0 : 1);' "$1" "$2" "$3" "$4"; }

rm -f "$WPD/wp-content/database/test.sqlite"; rm -rf "$WPD/wp-content/plugins/finet-troubleshoot"
$WP core install --url=http://127.0.0.1:8890 --title=Upgrade --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email >/dev/null 2>&1
$WP option update home http://127.0.0.1:8890 >/dev/null 2>&1; $WP option update siteurl http://127.0.0.1:8890 >/dev/null 2>&1
$WP rewrite structure '/%postname%/' >/dev/null 2>&1
$WP plugin install "$OLD_ZIP" --activate >/dev/null 2>&1 && pass "2.0.1 ZIP installed and activated" || fail "install 2.0.1"
$WP eval-file "$ROOT/tests/upgrade/seed-v2.php" 2>/dev/null | grep -q seeded && pass "data created with 2.0.1" || fail "seed"
$WP eval-file "$ROOT/tests/upgrade/snapshot.php" 2>/dev/null > "$OUT/before.json"

$WP plugin install "$NEW_ZIP" --force >/dev/null 2>&1 && pass "3.0 ZIP installed over 2.0.1 in place" || fail "install 3.0"
[[ "$($WP plugin list --name=finet-troubleshoot --field=status 2>/dev/null)" == "active" ]] && pass "plugin still active" || fail "plugin not active"
[[ "$($WP plugin list --field=name 2>/dev/null | grep -c finet)" == "1" ]] && pass "exactly one plugin instance (no parallel plugin)" || fail "duplicate plugin"
$WP eval 'do_action("admin_init");' --user=admin >/dev/null 2>&1   # what happens when an admin opens wp-admin
$WP eval-file "$ROOT/tests/upgrade/snapshot.php" 2>/dev/null > "$OUT/after.json"
compare "$OUT/before.json" "$OUT/after.json" posts,terms,relationships,settings,stats upgrade || FAIL=1
php -r '$b=json_decode(file_get_contents($argv[1]),true); $a=json_decode(file_get_contents($argv[2]),true);
echo ($b["db_version"]==="3.0.0"?"PASS":"FAIL")." db_version ".$a["db_version"]." -> ".$b["db_version"]."\n";
echo (!array_diff($a["caps"],$b["caps"])?"PASS":"FAIL")." capabilities kept\n";' "$OUT/after.json" "$OUT/before.json" | tee "$OUT/v.txt"; grep -q FAIL "$OUT/v.txt" && FAIL=1
$WP eval 'echo count(get_option("finet_ts_migration_log", array()));' 2>/dev/null | grep -qE '^[1-9]' && pass "migration log written" || fail "migration log"

"$ROOT/bin/serve-test-site.sh" >/dev/null
OUTAGE=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["posts"] as $p){ if($p["type"]==="wpfinet_outage"){ echo $p["permalink"]; } }' "$OUT/after.json")
for u in "http://127.0.0.1:8890/hjalp/" "http://127.0.0.1:8890/driftstorningar/" "$OUTAGE" "http://127.0.0.1:8890/driftstorningar/fiberavbrott-norr/"; do
	c=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' --max-time 40 "$u")
	[[ "${c%% *}" == "200" ]] && pass "HTTP 200 after upgrade: $u (${c#* } s)" || fail "HTTP ${c} after upgrade: $u"
done
[[ "$(curl -s http://127.0.0.1:8890/hjalp/ | grep -o 'finet-troubleshoot__data' | wc -l)" == "2" ]] && pass "both legacy guide shortcodes (id + slug) render" || fail "guide shortcodes"
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null

$WP option update finet_ts_db_version 2.0.0 >/dev/null 2>&1 && pass "rollback step 1: db_version set to 2.0.0 (prevents 2.0.1 from re-running its own 1.x migration)"
$WP plugin install "$OLD_ZIP" --force >/dev/null 2>&1 && pass "rollback: 2.0.1 ZIP reinstalled" || fail "rollback install"
$WP eval 'do_action("admin_init");' --user=admin >/dev/null 2>&1
$WP eval-file "$ROOT/tests/upgrade/snapshot.php" 2>/dev/null > "$OUT/rollback.json"
compare "$OUT/before.json" "$OUT/rollback.json" posts,terms,relationships,settings,stats rollback || FAIL=1
"$ROOT/bin/serve-test-site.sh" >/dev/null
c=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8890/hjalp/); [[ "$c" == "200" ]] && pass "rollback: guide page renders with 2.0.1" || fail "rollback page HTTP $c"
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null

rm -rf "$WPD/wp-content/plugins/finet-troubleshoot"; ln -sfn "$ROOT/finet-troubleshoot" "$WPD/wp-content/plugins/finet-troubleshoot"
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"
exit $FAIL

#!/usr/bin/env bash
# Real upgrade test: install an OLD release ZIP (2.0.1 or 3.0.0), create data, upgrade in place with the current
# build, compare, then roll back to the old ZIP. Usage: tests/upgrade/run-upgrade-test.sh /path/to/finet-troubleshoot-<old>.zip
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OLD_ZIP="${1:?path to finet-troubleshoot-<old>.zip}"
NEW_ZIP="$("$ROOT/bin/build-release.sh" | cut -d' ' -f1)"
NEW_VER="$(sed -n "s/^define( 'FINET_TS_VERSION', '\(.*\)' );/\1/p" "$ROOT/finet-troubleshoot/finet-troubleshoot.php")"
OLD_VER="$(unzip -p "$OLD_ZIP" finet-troubleshoot/finet-troubleshoot.php | sed -n 's/^ \* Version: *//p' | tr -d '\r ')"
echo "Upgrade $OLD_VER -> $NEW_VER"
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
$WP plugin install "$OLD_ZIP" --activate >/dev/null 2>&1 && pass "$OLD_VER ZIP installed and activated" || fail "install $OLD_VER"
$WP eval-file "$ROOT/tests/upgrade/seed-v2.php" 2>/dev/null | grep -q seeded && pass "data created with $OLD_VER" || fail "seed"
if [[ "$OLD_VER" == 3.* ]]; then
	$WP eval-file "$ROOT/tests/upgrade/seed-v3.php" 2>/dev/null | grep -q seeded && pass "3.x module data created (address index, Instagram, consent)" || fail "seed v3"
fi
$WP eval-file "$ROOT/tests/upgrade/snapshot.php" 2>/dev/null > "$OUT/before.json"

$WP plugin install "$NEW_ZIP" --force >/dev/null 2>&1 && pass "$NEW_VER ZIP installed over $OLD_VER in place" || fail "install $NEW_VER"
[[ "$($WP plugin list --name=finet-troubleshoot --field=status 2>/dev/null)" == "active" ]] && pass "plugin still active" || fail "plugin not active"
[[ "$($WP plugin list --field=name 2>/dev/null | grep -c finet)" == "1" ]] && pass "exactly one plugin instance (no parallel plugin)" || fail "duplicate plugin"
$WP eval 'do_action("admin_init");' --user=admin >/dev/null 2>&1   # what happens when an admin opens wp-admin
$WP eval-file "$ROOT/tests/upgrade/snapshot.php" 2>/dev/null > "$OUT/after.json"
compare "$OUT/before.json" "$OUT/after.json" posts,terms,relationships,settings,stats upgrade || FAIL=1
if [[ "$OLD_VER" == 3.* ]]; then
	php -r '$a=json_decode(file_get_contents($argv[1]),true)["v3"]; $b=json_decode(file_get_contents($argv[2]),true)["v3"];
	foreach ($a as $k => $v) { if ($k === "access_index_unfolded") { continue; } echo ($v === $b[$k] ? "PASS" : "FAIL") . " upgrade: $k unchanged\n"; }
	echo ($b["access_index_unfolded"] === 0 && $b["access_index_rows"] > 0 ? "PASS" : "FAIL") . " upgrade: street_fold filled for all " . $b["access_index_rows"] . " index rows\n";' "$OUT/before.json" "$OUT/after.json" | tee "$OUT/v3.txt"; grep -q FAIL "$OUT/v3.txt" && FAIL=1
	[[ "$($WP eval 'echo FinetTroubleshoot\Core\Secrets::get("access_api_secret");' 2>/dev/null)" == "upgrade-secret-123" ]] && pass "upgrade: encrypted secret from $OLD_VER still decrypts" || fail "secret lost after upgrade"
	$WP eval 'echo (new FinetTroubleshoot\Access\AccessService())->lookup("storgatan 12b")["message"];' 2>/dev/null | grep -q "Egen text: ansluten." && pass "upgrade: customised visitor texts kept" || fail "custom texts lost"
	$WP eval 'echo json_encode((new FinetTroubleshoot\Access\AccessService())->lookup("Östra Långgatan 3")["status"]);' 2>/dev/null | grep -q connected && pass "upgrade: old index answers accent-insensitive lookup without a new sync" || fail "folded lookup after upgrade"
fi
php -r '$b=json_decode(file_get_contents($argv[1]),true); $a=json_decode(file_get_contents($argv[2]),true);
echo ($b["db_version"]===$argv[3]?"PASS":"FAIL")." db_version ".$a["db_version"]." -> ".$b["db_version"]."\n";
echo (!array_diff($a["caps"],$b["caps"])?"PASS":"FAIL")." capabilities kept\n";' "$OUT/after.json" "$OUT/before.json" "$NEW_VER" | tee "$OUT/v.txt"; grep -q FAIL "$OUT/v.txt" && FAIL=1
$WP eval 'echo count(get_option("finet_ts_migration_log", array()));' 2>/dev/null | grep -qE '^[1-9]' && pass "migration log written" || fail "migration log"

"$ROOT/bin/serve-test-site.sh" >/dev/null
OUTAGE=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["posts"] as $p){ if($p["type"]==="wpfinet_outage"){ echo $p["permalink"]; } }' "$OUT/after.json")
for u in "http://127.0.0.1:8890/hjalp/" "http://127.0.0.1:8890/driftstorningar/" "$OUTAGE" "http://127.0.0.1:8890/driftstorningar/fiberavbrott-norr/"; do
	c=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' --max-time 40 "$u")
	[[ "${c%% *}" == "200" ]] && pass "HTTP 200 after upgrade: $u (${c#* } s)" || fail "HTTP ${c} after upgrade: $u"
done
[[ "$(curl -s http://127.0.0.1:8890/hjalp/ | grep -o 'finet-troubleshoot__data' | wc -l)" == "2" ]] && pass "both legacy guide shortcodes (id + slug) render" || fail "guide shortcodes"
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null

ROLLBACK_DB=$([[ "$OLD_VER" == 3.* ]] && echo "$OLD_VER" || echo 2.0.0)
$WP option update finet_ts_db_version "$ROLLBACK_DB" >/dev/null 2>&1 && pass "rollback step 1: db_version set to $ROLLBACK_DB (prevents the old version from re-running older migrations)"
$WP plugin install "$OLD_ZIP" --force >/dev/null 2>&1 && pass "rollback: $OLD_VER ZIP reinstalled" || fail "rollback install"
$WP eval 'do_action("admin_init");' --user=admin >/dev/null 2>&1
$WP eval-file "$ROOT/tests/upgrade/snapshot.php" 2>/dev/null > "$OUT/rollback.json"
compare "$OUT/before.json" "$OUT/rollback.json" posts,terms,relationships,settings,stats rollback || FAIL=1
"$ROOT/bin/serve-test-site.sh" >/dev/null
c=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8890/hjalp/); [[ "$c" == "200" ]] && pass "rollback: guide page renders with $OLD_VER" || fail "rollback page HTTP $c"
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null

rm -rf "$WPD/wp-content/plugins/finet-troubleshoot"; ln -sfn "$ROOT/finet-troubleshoot" "$WPD/wp-content/plugins/finet-troubleshoot"
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"
exit $FAIL

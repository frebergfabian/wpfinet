#!/usr/bin/env bash
# Consent manager E2E in LIVE mode + headless scanner run against the local site.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WPD="$ROOT/.test-env/wordpress"
WP="php $ROOT/.test-env/wp-cli.phar --path=$WPD --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"
SEED=$($WP eval-file "$ROOT/tests/e2e/seed.php" 2>/dev/null | tail -1)
mkdir -p "$WPD/wp-content/mu-plugins" && cp "$ROOT/tests/e2e/trackers-mu-plugin.php" "$WPD/wp-content/mu-plugins/wpfinet-e2e-trackers.php"
$WP post create --post_type=page --post_status=publish --post_title='Tracker test' --post_name=tracker-test --post_content='<p>Spårartest</p>' >/dev/null 2>&1
$WP eval 'FinetTroubleshoot\Core\Modules::update(array("consent"=>"live")); $s=get_option("finet_ts_settings"); FinetTroubleshoot\Consent\Policy::approve(1,"e2e");' >/dev/null 2>&1
APP=$($WP user application-password create admin scanner --porcelain 2>/dev/null | tail -1)
GUIDE_URL=$(echo "$SEED" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["page"];')
"$ROOT/bin/serve-test-site.sh" >/dev/null
GUIDE_URL=$GUIDE_URL node "$ROOT/tests/e2e/consent.mjs"; CODE=$?
cat > "$ROOT/.test-env/scanner.json" <<JSON
{ "site": "http://127.0.0.1:8890", "pages": ["/", "/tracker-test/", "/felsokning/"], "scenarios": ["before_consent", "reject", "accept_all"], "wordpress": { "user": "admin", "application_password": "$APP" }, "waitMs": 800, "timeoutMs": 8000 }
JSON
echo "--- scanner"
node "$ROOT/scanner/scan.mjs" --config "$ROOT/.test-env/scanner.json" 2>&1 | grep -v "^VARNING.*laddades inte helt" || CODE=1
$WP eval '$c=FinetTroubleshoot\Consent\Inventory::counts(); $r=array_map(fn($x)=>$x["name"]."(".$x["storage_type"].($x["before_consent"]?",FÖRE":"").")", FinetTroubleshoot\Consent\Inventory::all()); echo "inventory: ".json_encode($c)."\n".implode(" ", $r)."\n";' 2>/dev/null
rm -f "$WPD/wp-content/mu-plugins/wpfinet-e2e-trackers.php"
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null || true
exit $CODE

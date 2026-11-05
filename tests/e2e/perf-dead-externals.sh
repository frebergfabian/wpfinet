#!/usr/bin/env bash
# TTFB guardrail with all modules on and every external service hanging for 30 s. Target: median < 1 s, 0 outbound calls.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WPD="$ROOT/.test-env/wordpress"; WP="php $ROOT/.test-env/wp-cli.phar --path=$WPD --allow-root"
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"
$WP eval-file "$ROOT/tests/e2e/seed.php" >/dev/null 2>&1
$WP eval '
FinetTroubleshoot\Core\Modules::update(array("access"=>true,"instagram"=>true,"consent"=>"live"));
FinetTroubleshoot\Access\AccessSettings::update(array("adapter"=>"http","mode"=>"lookup","endpoint_path"=>"address","lookup_params"=>array("street"=>"s","number"=>"n","letter"=>"")));
FinetTroubleshoot\Core\Secrets::set("instagram_token","IGAAfaketoken1234567890abcdefghij");
FinetTroubleshoot\Instagram\InstagramService::update_state(array("username"=>"finet","token_obtained"=>time()));
update_option(FinetTroubleshoot\Instagram\InstagramService::MEDIA, array(array("id"=>"1","type"=>"IMAGE","permalink"=>"https://www.instagram.com/p/x/","timestamp"=>time(),"caption"=>"Test","file"=>"ig-1.jpg","width"=>1,"height"=>1)));
wp_insert_post(array("post_type"=>"page","post_status"=>"publish","post_name"=>"allt","post_title"=>"Allt","post_content"=>"[wpfinet_access_check][wpfinet_instagram_feed][wpfinet_outages][wpfinet_outage_status][finet_troubleshoot slug=\"e2e-guide\"][wpfinet_cookie_policy]"));
update_option("page_on_front", get_page_by_path("allt")->ID);
' >/dev/null 2>&1
mkdir -p "$WPD/wp-content/mu-plugins"; cp "$ROOT/tests/e2e/dead-externals-mu-plugin.php" "$WPD/wp-content/mu-plugins/zz-dead-externals.php"; : > "$WPD/wp-content/outbound.log"
"$ROOT/bin/serve-test-site.sh" >/dev/null
FAIL=0
for u in "/" "/allt/" "/felsokning/" "/driftstorningar/" "/adresskoll/"; do
	COLD=$(curl -s -o /dev/null -w '%{time_starttransfer}' --max-time 40 "http://127.0.0.1:8890$u")
	MED=$(for i in $(seq 1 9); do curl -s -o /dev/null -w '%{time_starttransfer}\n' --max-time 40 "http://127.0.0.1:8890$u"; done | sort -n | sed -n 5p)
	OK=$(php -r "echo ($MED < 1.0 && $COLD < 1.0) ? 'PASS' : 'FAIL';"); [[ $OK == PASS ]] || FAIL=1
	echo "$OK TTFB $u  first: ${COLD}s  median(9): ${MED}s"
done
LOOKUP=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' --max-time 40 -X POST -H 'Content-Type: application/json' -d '{"address":"Storgatan 1"}' http://127.0.0.1:8890/wp-json/wpfinet/v1/access/check)
echo "INFO address lookup while API hangs (lookup mode, timeout 3 s – simulated hang is 30 s in pre_http_request, so this shows the WORST case of the simulation): $LOOKUP"
CALLS=$(grep -vc '/wp-json/wpfinet/v1/access/check' "$WPD/wp-content/outbound.log" || true)
[[ "$CALLS" == "0" ]] && echo "PASS 0 outbound HTTP calls during page rendering" || { echo "FAIL $CALLS outbound calls during page rendering:"; grep -v access/check "$WPD/wp-content/outbound.log" | head; FAIL=1; }
kill "$(cat "$ROOT/.test-env/server.pid")" 2>/dev/null
rm -f "$WPD/wp-content/mu-plugins/zz-dead-externals.php"
cp "$ROOT/.test-env/clean.sqlite" "$WPD/wp-content/database/test.sqlite"
exit $FAIL

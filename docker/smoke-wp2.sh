#!/usr/bin/env bash
# WP-2 checks through real HTTP requests, inside a stand container after install-wp.sh.
# UptimeWire is played by docker/fake-api.php (a must-use plugin of the stand).
#   smoke-wp2.sh <port>
# Prints PASS/FAIL lines; exits non-zero when anything failed.
set -uo pipefail
PORT="$1"
BASE="http://localhost:$PORT"
# WP-CLI itself prints PHP 8.5 deprecations into its output; they are kept out of the values read here.
WP="php -d display_errors=0 -d error_reporting=0 /usr/local/bin/wp --allow-root --path=/var/www/html"
CURL="curl -s --connect-to localhost:$PORT:127.0.0.1:80"
TMP=$(mktemp -d)
FAILS=0
LOG=/var/www/html/wp-content/fake-api-log.txt
DEVICE_CODE=uwd_fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210
TOKEN=uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
AUTH_ID=0196a3b2-1c2d-7e3f-8a9b-0c1d2e3f4a5b
DEVICE='[200,{"device_code":"'$DEVICE_CODE'","user_code":"WDJB-MJHT","verification_uri":"https://app.uptimewire.com/connect","verification_uri_complete":"https://app.uptimewire.com/connect?code=WDJB-MJHT","expires_in":600,"interval":5,"authorization_id":"'$AUTH_ID'"}]'
PENDING='[400,{"error":"authorization_pending","error_description":"x"}]'
GRANTED='[200,{"access_token":"'$TOKEN'","token_type":"Bearer","connection":{"id":"0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b","connected_at":"2026-11-23T08:37:39+00:00","site":{"url":"https://www.customer-site.com"}}}]'

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
check() { if grep -q -- "$2" "$3"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
absent() { if grep -q -- "$2" "$3"; then fail "$1 (found: $2)"; else pass "$1"; fi; }
calls() { grep -c "^$1 " "$LOG" 2>/dev/null || true; }
fake() { $WP option update uwtest_fake_api "$1" --format=json >/dev/null; : > "$LOG"; chmod 666 "$LOG"; }
due() { $WP eval '$p = get_transient( "uptimewire_pending" ); $p["next_poll_at"] = time() - 1; set_transient( "uptimewire_pending", $p, 900 );' >/dev/null; }
reset_plugin() { $WP eval 'foreach ( array( "uptimewire_connection", "uptimewire_outcome", "uptimewire_lock_poll", "uptimewire_lock_start", "uptimewire_lock_health" ) as $o ) { delete_option( $o ); } foreach ( array( "uptimewire_pending", "uptimewire_cancelled", "uptimewire_proof_seen" ) as $t ) { delete_transient( $t ); }' >/dev/null; }
login() { # user password jar
	$CURL -c "$3" -b "$3" -o /dev/null "$BASE/wp-login.php"
	$CURL -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data "testcookie=1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}
page() { $CURL -b "$1" -o "$2" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=${3:-uptimewire}"; }
form_nonce() { perl -0ne 'print $1 if /value="'"$1"'">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)"/' "$2"; }
rest_nonce() { perl -ne 'print $1 if /uptimewireConnect = {.*?"nonce":"([0-9a-f]+)"/' "$1"; }
rest() { # jar nonce route [extra curl args]
	local jar="$1" nonce="$2" route="$3"; shift 3
	$CURL -b "$jar" -H "X-WP-Nonce: $nonce" -H 'Content-Type: application/json' -X POST "$@" "$BASE/?rest_route=/uptimewire/v1/$route"
}
post() { $CURL -b "$1" -o /dev/null -w '%{http_code} %{redirect_url}' --data "$2" "$BASE/wp-admin/admin-post.php"; }

# Stand setup.
$WP config set WP_DEBUG true --raw >/dev/null
$WP config set WP_DEBUG_LOG true --raw >/dev/null
$WP config set WP_DEBUG_DISPLAY false --raw >/dev/null
mkdir -p /var/www/html/wp-content/mu-plugins
cp /src/docker/fake-api.php /var/www/html/wp-content/mu-plugins/uw-fake-api.php
rm -f /var/www/html/wp-content/mu-plugins/uw-block-bot.php /var/www/html/wp-content/mu-plugins/uw-live-forward.php /var/www/html/wp-content/debug.log
$WP user get editor >/dev/null 2>&1 || $WP user create editor editor@example.com --role=editor --user_pass=stand-editor-pass >/dev/null
$WP plugin is-active uptimewire || $WP plugin activate uptimewire >/dev/null
reset_plugin
$WP option update uptimewire_site_url "https://www.customer-site.com/" --autoload=no >/dev/null
chown -R www-data:www-data /var/www/html/wp-content
login admin stand-admin-pass "$TMP/admin"
login editor stand-editor-pass "$TMP/editor"

echo "--- welcome screen"
fake '{}'
[ "$(page "$TMP/admin" "$TMP/welcome.html")" = 200 ] && pass "overview 200" || fail "overview"
absent "buttons enabled" '<fieldset disabled' "$TMP/welcome.html"
check "connect form" 'value="uptimewire_connect"' "$TMP/welcome.html"
check "external service said" 'UptimeWire is an external service' "$TMP/welcome.html"
check "script on overview" 'assets/connect.js' "$TMP/welcome.html"
check "rest nonce for the script" '"nonce":"' "$TMP/welcome.html"
[ "$(calls /wp/v1/device)" = 0 ] && pass "nothing sent before a click" || fail "sent before a click"
$CURL -b "$TMP/admin" -o "$TMP/dash.html" "$BASE/wp-admin/index.php"
absent "no script on the dashboard" 'connect.js' "$TMP/dash.html"
NONCE_CONNECT=$(form_nonce uptimewire_connect "$TMP/welcome.html")
NONCE_REST=$(rest_nonce "$TMP/welcome.html")
[ -n "$NONCE_CONNECT" ] && [ -n "$NONCE_REST" ] && pass "nonces found" || fail "nonces not found"

echo "--- 12, 13: capability and nonce"
r=$(post "$TMP/admin" "action=uptimewire_connect")
case "$r" in 403*) pass "connect without nonce: 403" ;; *) fail "connect without nonce: $r" ;; esac
r=$(post "$TMP/admin" "action=uptimewire_connect&_wpnonce=0badc0de00")
case "$r" in 403*) pass "connect with a wrong nonce: 403" ;; *) fail "wrong nonce: $r" ;; esac
r=$(post "$TMP/editor" "action=uptimewire_connect&_wpnonce=$NONCE_CONNECT")
case "$r" in 403*) pass "editor cannot connect" ;; *) fail "editor connect: $r" ;; esac
code=$($CURL -b "$TMP/admin" -X POST -o "$TMP/r.json" -w "%{http_code}" "$BASE/?rest_route=/uptimewire/v1/connect-start")
[ "$code" = 401 ] && pass "REST without nonce: 401 (signed out for REST)" || fail "REST without nonce: $code $(cat "$TMP/r.json")"
code=$(rest "$TMP/admin" 0badc0de00 connect-start -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 403 ] && grep -q rest_cookie_invalid_nonce "$TMP/r.json" && pass "REST with a wrong nonce: 403" || fail "REST wrong nonce: $code $(cat "$TMP/r.json")"
$CURL -b "$TMP/editor" -o "$TMP/ed.html" "$BASE/wp-admin/index.php"
code=$(rest "$TMP/editor" "$NONCE_REST" connect-start -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 403 ] && pass "editor REST: 403" || fail "editor REST: $code"
[ "$(calls /wp/v1/device)" = 0 ] && pass "refused requests sent nothing" || fail "refused requests reached UptimeWire"

echo "--- 9: double click (three parallel starts)"
fake '{"delay":2,"/wp/v1/device":['"$DEVICE"']}'
for i in 1 2 3; do rest "$TMP/admin" "$NONCE_REST" connect-start -o "$TMP/start$i.json" & done; wait
[ "$(calls /wp/v1/device)" = 1 ] && pass "one code requested" || fail "codes requested: $(calls /wp/v1/device)"
grep -q '"ok":true' "$TMP/start1.json" && grep -q '"ok":true' "$TMP/start2.json" && grep -q '"ok":true' "$TMP/start3.json" && pass "all three clicks lead to the waiting screen" || fail "starts: $(cat "$TMP"/start*.json)"
grep -q 'connect?code=WDJB-MJHT' "$TMP/start1.json" && pass "the tab opens the code page" || fail "open: $(cat "$TMP/start1.json")"

echo "--- 10: waiting screen and reload"
page "$TMP/admin" "$TMP/wait.html" >/dev/null
check "waiting title" 'Finish Connecting UptimeWire' "$TMP/wait.html"
check "code shown" 'WDJB-MJHT' "$TMP/wait.html"
check "cancel form" 'value="uptimewire_connect_cancel"' "$TMP/wait.html"
page "$TMP/admin" "$TMP/wait2.html" >/dev/null
check "reload keeps the code" 'WDJB-MJHT' "$TMP/wait2.html"
[ "$(calls /wp/v1/device)" = 1 ] && pass "reload asks nothing" || fail "reload asked again"

echo "--- 14, 15: proof answer"
PROOF=$($WP eval 'echo get_transient( "uptimewire_pending" )["proof"];')
$CURL -D "$TMP/proof.h" -o "$TMP/proof.json" -A 'UptimeWireBot/1.0 (+https://uptimewire.com)' "$BASE/?uptimewire_proof=$AUTH_ID"
grep -q "^HTTP/1.1 200" "$TMP/proof.h" && pass "proof 200" || fail "proof status $(head -1 "$TMP/proof.h")"
[ "$(cat "$TMP/proof.json")" = "{\"proof\":\"$PROOF\"}" ] && pass "proof body is exactly the proof" || fail "proof body: $(head -c 200 "$TMP/proof.json")"
grep -qi '^cache-control:.*no-store' "$TMP/proof.h" && pass "no-store" || fail "cache-control: $(grep -i cache-control "$TMP/proof.h")"
grep -qi '^x-robots-tag: noindex' "$TMP/proof.h" && pass "noindex" || fail "x-robots-tag"
grep -qi '^content-type: application/json' "$TMP/proof.h" && pass "json" || fail "content-type"
absent "no cookie with the proof" '^Set-Cookie' "$TMP/proof.h"
$WP eval 'echo get_transient( "uptimewire_proof_seen" );' | grep -q "$AUTH_ID" && pass "proof request noted" || fail "proof request not noted"
$CURL -o "$TMP/home.html" "$BASE/"
$CURL -o "$TMP/wrong.html" -w '%{http_code}' "$BASE/?uptimewire_proof=0196a3b2-1c2d-7e3f-8a9b-000000000000" > "$TMP/wrong.code"
cmp -s "$TMP/home.html" "$TMP/wrong.html" && pass "another ID gets the normal page" || fail "another ID differs from the home page"
absent "no proof for another ID" "$PROOF" "$TMP/wrong.html"
$CURL -o "$TMP/arr.html" "$BASE/?uptimewire_proof[]=$AUTH_ID"
absent "no proof for an array" "$PROOF" "$TMP/arr.html"
$CURL -o "$TMP/postp.html" --data "x=1" "$BASE/?uptimewire_proof=$AUTH_ID"
absent "no proof for POST" "$PROOF" "$TMP/postp.html"

echo "--- 8: no two polls at once (three parallel status requests)"
fake '{"delay":2,"/wp/v1/token":['"$PENDING"']}'
due
for i in 1 2 3; do rest "$TMP/admin" "$NONCE_REST" connect-status -o "$TMP/st$i.json" & done; wait
[ "$(calls /wp/v1/token)" = 1 ] && pass "one question to UptimeWire" || fail "questions: $(calls /wp/v1/token)"
grep -q '"state":"waiting_for_approval"' "$TMP/st1.json" "$TMP/st2.json" "$TMP/st3.json" && pass "answers carry the state" || fail "status answers: $(cat "$TMP"/st*.json)"
rest "$TMP/admin" "$NONCE_REST" connect-status -o "$TMP/st4.json"
[ "$(calls /wp/v1/token)" = 1 ] && pass "no question before the interval" || fail "asked early"
grep -q '"retry_after":' "$TMP/st4.json" && pass "the browser is told when to ask" || fail "no retry_after"

echo "--- 3, 24: approval arrives"
fake '{"/wp/v1/token":['"$GRANTED"']}'
due
rest "$TMP/admin" "$NONCE_REST" connect-status -o "$TMP/done.json"
grep -q '"done":true' "$TMP/done.json" && grep -q 'uptimewire_notice=connected' "$TMP/done.json" && pass "done, back to Overview with the message" || fail "done: $(cat "$TMP/done.json")"
page "$TMP/admin" "$TMP/connected.html" "uptimewire&uptimewire_notice=connected" >/dev/null
check "connected message" 'Your WordPress website is connected to UptimeWire.' "$TMP/connected.html"
check "state" 'Connection Established' "$TMP/connected.html"
absent "no script when connected" 'connect.js' "$TMP/connected.html"
page "$TMP/admin" "$TMP/settings.html" uptimewire-settings >/dev/null
check "settings state" 'Connection Established' "$TMP/settings.html"
$CURL -o "$TMP/after.html" "$BASE/?uptimewire_proof=$AUTH_ID"
absent "no proof after the connection" "$PROOF" "$TMP/after.html"
rows=$($WP eval 'global $wpdb; echo implode( " ", $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"%uptimewire%\" ORDER BY option_name" ) );')
[ "$rows" = "uptimewire_connection uptimewire_site_url" ] && pass "only the connection is kept: $rows" || fail "rows: $rows"
autoload=$($WP eval 'global $wpdb; echo $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = \"uptimewire_connection\"" );')
case "$autoload" in no|off) pass "credential not autoloaded ($autoload)" ;; *) fail "autoload=$autoload" ;; esac
$CURL -b "$TMP/admin" -o "$TMP/options.html" "$BASE/wp-admin/options.php"
absent "options.php does not show the credential" "$TOKEN" "$TMP/options.html"

echo "--- 23: secrets in pages, answers and logs"
cat "$TMP"/*.html "$TMP"/*.json > "$TMP/all.txt"
for secret in "$DEVICE_CODE" "$TOKEN" uwd_ uwp_; do absent "no $secret in pages and answers" "$secret" "$TMP/all.txt"; done
leaks=$(grep -l "$PROOF" "$TMP"/*.html "$TMP"/st*.json "$TMP"/start*.json "$TMP"/done.json 2>/dev/null)
[ -z "$leaks" ] && pass "proof only in its own answer" || fail "proof found in: $leaks"
$WP eval 'require_once ABSPATH . "wp-admin/includes/class-wp-debug-data.php"; echo wp_json_encode( WP_Debug_Data::debug_data() );' > "$TMP/health.txt" 2>/dev/null
absent "Site Health info has no credential" "$TOKEN" "$TMP/health.txt"
# Lines of WP-CLI itself (its PHP 8.5 deprecations) are not from WordPress or the plugin.
logged=$(grep -v "phar:///usr/local/bin/wp/" /var/www/html/wp-content/debug.log 2>/dev/null)
ours=$(echo "$logged" | grep -cE "plugins/uptimewire|Fatal")
if [ "$ours" != 0 ]; then fail "debug.log has lines from the plugin:"; echo "$logged" | grep -E "plugins/uptimewire|Fatal" | head -20
elif [ -n "$logged" ]; then pass "debug.log: nothing from the plugin ($(echo "$logged" | wc -l) lines from WordPress core itself: $(echo "$logged" | head -1 | cut -c27-120))"
else pass "debug.log has nothing from WordPress or the plugin"; fi

echo "--- 22: credential in wp-config.php (R7)"
$WP config set UPTIMEWIRE_TOKEN "$TOKEN" >/dev/null; sleep 3
page "$TMP/admin" "$TMP/cfg.html" uptimewire-settings >/dev/null
check "credential from wp-config.php" 'Kept in wp-config.php' "$TMP/cfg.html"
$WP eval 'echo isset( get_option( "uptimewire_connection" )["token"] ) ? "db" : "none";' | grep -q none && pass "database copy removed" || fail "database copy kept"
absent "constant value not shown" "$TOKEN" "$TMP/cfg.html"
$WP config set UPTIMEWIRE_TOKEN "not-a-credential" >/dev/null; sleep 3
page "$TMP/admin" "$TMP/cfgbad.html" >/dev/null
check "malformed constant reported" 'is not a valid UptimeWire credential' "$TMP/cfgbad.html"
absent "malformed value not shown" 'not-a-credential' "$TMP/cfgbad.html"
NONCE_CONNECT2=$(form_nonce uptimewire_connect "$TMP/welcome.html")
r=$(post "$TMP/admin" "action=uptimewire_connect&_wpnonce=$NONCE_CONNECT2")
case "$r" in *uptimewire_notice=token_in_config*) pass "no new connection while the constant is set" ;; *) fail "constant start: $r" ;; esac
$WP config delete UPTIMEWIRE_TOKEN >/dev/null; sleep 3

echo "--- 19: staging site"
reset_plugin
$WP config set WP_ENVIRONMENT_TYPE staging >/dev/null; sleep 3
page "$TMP/admin" "$TMP/staging.html" >/dev/null
check "staging warning" 'This looks like a staging or development site' "$TMP/staging.html"
check "required confirmation" 'name="uptimewire_understand" value="1" required' "$TMP/staging.html"
fake '{"/wp/v1/device":['"$DEVICE"']}'
N=$(form_nonce uptimewire_connect "$TMP/staging.html")
r=$(post "$TMP/admin" "action=uptimewire_connect&_wpnonce=$N")
case "$r" in *needs_confirmation*) pass "not confirmed: refused" ;; *) fail "staging without confirmation: $r" ;; esac
[ "$(calls /wp/v1/device)" = 0 ] && pass "nothing sent without the confirmation" || fail "sent without confirmation"
r=$(post "$TMP/admin" "action=uptimewire_connect&_wpnonce=$N&uptimewire_understand=1")
grep -q '"env":"staging"' "$LOG" && pass "confirmed: the code is requested with env=staging" || fail "staging request: $r $(cat "$LOG")"
$WP config delete WP_ENVIRONMENT_TYPE >/dev/null; sleep 3

echo "--- cancel and dismiss (forms)"
page "$TMP/admin" "$TMP/w3.html" >/dev/null
r=$(post "$TMP/admin" "action=uptimewire_connect_cancel&_wpnonce=$(form_nonce uptimewire_connect_cancel "$TMP/w3.html")")
case "$r" in *uptimewire_notice=cancelled*) pass "cancelled" ;; *) fail "cancel: $r" ;; esac
[ -z "$($WP eval 'echo get_transient( "uptimewire_pending" ) ? "x" : "";')" ] && pass "pending code forgotten" || fail "pending kept"

echo "--- 16: a security plugin blocks the check; a page cache"
cat > /var/www/html/wp-content/mu-plugins/uw-block-bot.php <<'PHP'
<?php
// Stand only: behaves like a security plugin that blocks unknown bots before other plugins run.
if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && false !== strpos( $_SERVER['HTTP_USER_AGENT'], 'UptimeWireBot' ) ) { status_header( 403 ); exit( 'Blocked by security plugin' ); }
PHP
fake '{"/wp/v1/device":['"$DEVICE"']}'
r=$(post "$TMP/admin" "action=uptimewire_connect&_wpnonce=$N")
$CURL -o "$TMP/blocked.txt" -w '%{http_code}' -A 'UptimeWireBot/1.0 (+https://uptimewire.com)' "$BASE/?uptimewire_proof=$AUTH_ID" > "$TMP/blocked.code"
[ "$(cat "$TMP/blocked.code")" = 403 ] && pass "blocked check: 403, as UptimeWire would see it" || fail "block: $(cat "$TMP/blocked.code")"
[ -z "$($WP eval 'echo get_transient( "uptimewire_proof_seen" );')" ] && pass "the plugin never saw the check" || fail "proof seen despite the block"
$WP eval '$p = get_transient( "uptimewire_pending" ); $p["expires_at"] = time() - 200; set_transient( "uptimewire_pending", $p, 900 );' >/dev/null
page "$TMP/admin" "$TMP/expired.html" >/dev/null
check "expired after a block" 'Authorization Expired' "$TMP/expired.html"
check "help opened: Cloudflare and security plugins" '<details class="uptimewire-help" open>' "$TMP/expired.html"
check "firewall guide link" 'allow-uptimewire-checks-through-firewall' "$TMP/expired.html"
rm -f /var/www/html/wp-content/mu-plugins/uw-block-bot.php

if $WP plugin install cache-enabler --activate >/dev/null 2>&1; then
	reset_plugin
	fake '{"/wp/v1/device":['"$DEVICE"']}'
	N=$(form_nonce uptimewire_connect "$TMP/welcome.html")
	post "$TMP/admin" "action=uptimewire_connect&_wpnonce=$N" >/dev/null
	PROOF=$($WP eval 'echo get_transient( "uptimewire_pending" )["proof"];')
	chown -R www-data:www-data /var/www/html/wp-content
	$CURL -o /dev/null "$BASE/"; $CURL -o "$TMP/cached.html" -D "$TMP/cached.h" "$BASE/"
	$CURL -o "$TMP/proof2.json" "$BASE/?uptimewire_proof=$AUTH_ID"
	$CURL -o "$TMP/home2.html" "$BASE/"
	$CURL -o "$TMP/proof3.json" "$BASE/?uptimewire_proof=$AUTH_ID"
	[ "$(cat "$TMP/proof2.json")" = "{\"proof\":\"$PROOF\"}" ] && pass "page cache on: proof answered" || fail "page cache: $(head -c 120 "$TMP/proof2.json")"
	absent "page cache: the home page is not the proof" "$PROOF" "$TMP/home2.html"
	[ "$(cat "$TMP/proof3.json")" = "{\"proof\":\"$PROOF\"}" ] && pass "page cache: proof not replaced by a cached page" || fail "page cache 2nd: $(head -c 120 "$TMP/proof3.json")"
	grep -rl "$PROOF" /var/www/html/wp-content/cache 2>/dev/null | head -1 | grep -q . && fail "proof stored in the page cache" || pass "proof not in the page cache files ($(find /var/www/html/wp-content/cache -type f 2>/dev/null | wc -l) cached files)"
	$WP plugin deactivate cache-enabler >/dev/null; $WP plugin delete cache-enabler >/dev/null
else
	echo "SKIP page cache: cache-enabler could not be installed"
fi

echo "--- uninstall"
reset_plugin
fake '{"/wp/v1/device":['"$DEVICE"']}'
post "$TMP/admin" "action=uptimewire_connect&_wpnonce=$N" >/dev/null
$WP option update uptimewire_connection '{"id":"x","token":"'$TOKEN'"}' --format=json --autoload=no >/dev/null
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null && pass "uninstall ran" || fail "uninstall failed"
left=$($WP eval 'global $wpdb; echo implode( " ", $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"%uptimewire\\_%\" ORDER BY option_name" ) ), " ";')
[ "$left" = " " ] && pass "nothing left after uninstall" || fail "left after uninstall: $left"
cp -r /src/build/uptimewire /var/www/html/wp-content/plugins/uptimewire
chown -R www-data:www-data /var/www/html/wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null
$WP option delete uwtest_fake_api >/dev/null
rm -f /var/www/html/wp-content/mu-plugins/uw-fake-api.php

rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

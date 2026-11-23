#!/usr/bin/env bash
# WP-3 checks through real HTTP requests, inside a stand container after install-wp.sh.
# UptimeWire is played by docker/fake-api.php (a must-use plugin of the stand).
#   smoke-wp3.sh <port>
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
TOKEN=uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
check() { if grep -q -- "$2" "$3"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
absent() { if grep -q -- "$2" "$3"; then fail "$1 (found: $2)"; else pass "$1"; fi; }
calls() { grep -c "^$1 " "$LOG" 2>/dev/null || true; }
login() { # user password jar
	$CURL -c "$3" -b "$3" -o /dev/null "$BASE/wp-login.php"
	$CURL -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data "testcookie=1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}
page() { $CURL -b "$1" -o "$2" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=${3:-uptimewire}"; }
form_nonce() { perl -0ne 'print $1 if /value="'"$1"'">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)"/' "$2"; }
rest_nonce() { perl -ne 'print $1 if /uptimewireDashboard = {.*?"nonce":"([0-9a-f]+)"/' "$1"; }
rest() { # jar nonce [extra curl args]
	local jar="$1" nonce="$2"; shift 2
	$CURL -b "$jar" -H "X-WP-Nonce: $nonce" -H 'Content-Type: application/json' -X POST "$@" "$BASE/?rest_route=/uptimewire/v1/status-refresh"
}
post() { $CURL -b "$1" -o /dev/null -w '%{http_code} %{redirect_url}' --data "$2" "$BASE/wp-admin/admin-post.php"; }
at() { date -u -d "@$(( $(date +%s) - $1 ))" +%Y-%m-%dT%H:%M:%S+00:00; }
status_json() { # site state
	cat <<JSON
[200,{"data":{"connection":{"id":"0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b","state":"active","connected_at":"2026-11-23T08:37:39+00:00","account_label":"ACC-00001000"},
"site":{"url":"https://www.customer-site.com","created_at":"$(at 7776000)","state":"$1","paused":false,"awaiting_email_confirmation":false,"interval_minutes":5,"problem":"<script>alert(1)</script>","down_since":null,"last_checked_at":"$(at 120)","last_success_at":"$(at 120)","last_location":"Germany","average_response_ms_24h":412,"blocked":null},
"uptime":[{"window":"24h","from":"$(at 86400)","to":"$(at 0)","measured_seconds":86400,"downtime_seconds":0,"percent":100.0,"history_limited":false},{"window":"7d","from":"$(at 604800)","to":"$(at 0)","measured_seconds":604800,"downtime_seconds":600,"percent":99.9,"history_limited":false},{"window":"30d","from":"$(at 2592000)","to":"$(at 0)","measured_seconds":2592000,"downtime_seconds":600,"percent":99.97,"history_limited":false}],
"checks":{"ssl":{"state":"ok","checked_at":"$(at 3600)","expires_at":"$(at -6825600)","days_left":79,"warning":null,"error":null},"domain":{"state":"ok","checked_at":"$(at 3600)","expires_at":null,"days_left":null,"expiry_known":false,"warning":null,"error":null},"dns":{"state":"ok","checked_at":"$(at 3600)","changed":false,"open_changes":0,"last_change_at":null}},
"events":[],"alerts":{"ready":true,"channels":["email","telegram"],"email_pending":false},
"links":{"site":"https://app.uptimewire.com/monitors/0193a3b2/view","alerts":"https://app.uptimewire.com/user/profile/notifications","plans":"https://app.uptimewire.com/subscription-plans"}},
"meta":{"server_time":"$(at 0)"}},{"cache-control":"no-store"}]
JSON
}
fake() { # status answer as JSON array
	$WP option update uwtest_fake_api "{\"/wp/v1/status\":[$1]}" --format=json >/dev/null; : > "$LOG"; chmod 666 "$LOG"
}

# Stand setup.
$WP config set WP_DEBUG true --raw >/dev/null
$WP config set WP_DEBUG_LOG true --raw >/dev/null
$WP config set WP_DEBUG_DISPLAY false --raw >/dev/null
mkdir -p /var/www/html/wp-content/mu-plugins
cp /src/docker/fake-api.php /var/www/html/wp-content/mu-plugins/uw-fake-api.php
rm -f /var/www/html/wp-content/mu-plugins/uw-block-bot.php /var/www/html/wp-content/mu-plugins/uw-live-forward.php /var/www/html/wp-content/debug.log
$WP user get editor >/dev/null 2>&1 || $WP user create editor editor@example.com --role=editor --user_pass=stand-editor-pass >/dev/null
$WP plugin is-active uptimewire || $WP plugin activate uptimewire >/dev/null
$WP eval 'foreach ( array( "uptimewire_connection", "uptimewire_outcome", "uptimewire_status", "uptimewire_lock_status", "uptimewire_lock_health" ) as $o ) { delete_option( $o ); }' >/dev/null
$WP option update uptimewire_site_url "https://www.customer-site.com/" --autoload=no >/dev/null
$WP eval 'UptimeWire\Connection::save( "'$TOKEN'", array( "id" => "0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b", "connected_at" => "2026-11-23T08:37:39+00:00", "site_url" => "https://www.customer-site.com", "local_url" => "https://www.customer-site.com/", "connected_by" => 1, "account_label" => "ACC-00001000" ) );' >/dev/null
chown -R www-data:www-data /var/www/html/wp-content
login admin stand-admin-pass "$TMP/admin"
login editor stand-editor-pass "$TMP/editor"

echo "--- render without waiting for UptimeWire"
fake "$(status_json up)"
[ "$(page "$TMP/admin" "$TMP/overview.html")" = 200 ] && pass "overview 200" || fail "overview"
check "dashboard container" 'id="uptimewire-dashboard"' "$TMP/overview.html"
check "loading state first" 'Loading Your Website Status' "$TMP/overview.html"
check "due for the script" 'data-due="1"' "$TMP/overview.html"
check "account number from connecting" 'ACC-00001000' "$TMP/overview.html"
check "dashboard script" 'assets/dashboard.js' "$TMP/overview.html"
absent "no connect script" 'assets/connect.js' "$TMP/overview.html"
absent "no credential in the page" 'uwp_' "$TMP/overview.html"
[ "$(calls /wp/v1/status)" = 0 ] && pass "no request while rendering" || fail "requested while rendering"
NONCE_REST=$(rest_nonce "$TMP/overview.html")
NONCE_FORM=$(form_nonce uptimewire_refresh "$TMP/overview.html")
[ -n "$NONCE_REST" ] && [ -n "$NONCE_FORM" ] && pass "nonces found" || fail "nonces not found"

echo "--- assets only on the plugin's screens"
$CURL -b "$TMP/admin" -o "$TMP/dash.html" "$BASE/wp-admin/index.php"
check "the Dashboard widget refreshes with the same script (WP-4)" 'uptimewire/assets/dashboard.js' "$TMP/dash.html"
absent "no styles on the WordPress dashboard" 'uptimewire/assets/admin.css' "$TMP/dash.html"
page "$TMP/admin" "$TMP/settings.html" uptimewire-settings >/dev/null
absent "no dashboard script on Settings" 'uptimewire/assets/dashboard.js' "$TMP/settings.html"

echo "--- REST refresh: CSRF and capability"
code=$($CURL -b "$TMP/admin" -X POST -o "$TMP/r.json" -w '%{http_code}' "$BASE/?rest_route=/uptimewire/v1/status-refresh")
[ "$code" = 401 ] && pass "without nonce: 401" || fail "without nonce: $code $(cat "$TMP/r.json")"
code=$(rest "$TMP/admin" 0badc0de00 -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 403 ] && grep -q rest_cookie_invalid_nonce "$TMP/r.json" && pass "wrong nonce: 403" || fail "wrong nonce: $code"
code=$(rest "$TMP/editor" "$NONCE_REST" -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 403 ] && pass "editor: 403" || fail "editor: $code $(cat "$TMP/r.json")"
code=$($CURL -o "$TMP/r.json" -w '%{http_code}' -X POST "$BASE/?rest_route=/uptimewire/v1/status-refresh")
[ "$code" = 401 ] && pass "signed out: 401" || fail "signed out: $code"
[ "$(calls /wp/v1/status)" = 0 ] && pass "refused requests asked nothing" || fail "refused requests reached UptimeWire"

echo "--- REST refresh: reads once, answers the dashboard"
code=$(rest "$TMP/admin" "$NONCE_REST" -D "$TMP/h.txt" -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 200 ] && pass "refresh 200" || fail "refresh: $code $(head -c 300 "$TMP/r.json")"
grep -qiE '^cache-control:.*no-store' "$TMP/h.txt" && pass "answer not cached" || fail "cache header"
check "online in the answer" 'Your Website Is Online' "$TMP/r.json"
check "announcement" '"announce":"Your Website Is Online"' "$TMP/r.json"
absent "problem text escaped" '<script>alert' "$TMP/r.json"
absent "no credential in the answer" 'uwp_' "$TMP/r.json"
rest "$TMP/admin" "$NONCE_REST" -o "$TMP/r2.json"
check "second read reuses the copy" '"result":"fresh"' "$TMP/r2.json"
[ "$(calls /wp/v1/status)" = 1 ] && pass "one request for two reads" || fail "requests: $(calls /wp/v1/status)"
page "$TMP/admin" "$TMP/overview2.html" >/dev/null
check "rendered from the copy" 'Your Website Is Online' "$TMP/overview2.html"
check "not due" 'data-due="0"' "$TMP/overview2.html"
[ "$(calls /wp/v1/status)" = 1 ] && pass "render still asks nothing" || fail "render asked"

echo "--- Refresh without JavaScript"
r=$(post "$TMP/admin" "action=uptimewire_refresh")
case "$r" in 403*) pass "form without nonce: 403" ;; *) fail "form without nonce: $r" ;; esac
r=$(post "$TMP/editor" "action=uptimewire_refresh&_wpnonce=$NONCE_FORM")
case "$r" in 403*) pass "editor form: 403" ;; *) fail "editor form: $r" ;; esac
r=$(post "$TMP/admin" "action=uptimewire_refresh&_wpnonce=$NONCE_FORM")
case "$r" in 302*uptimewire_notice=status_refreshed*) pass "form: back to Overview" ;; *) fail "form: $r" ;; esac

echo "--- 503 keeps the last known status"
$WP eval '$s = get_option( "uptimewire_status" ); $s["fetched_at"] -= 3600; $s["attempted_at"] -= 3600; update_option( "uptimewire_status", $s, false );' >/dev/null
fake '[503,{"error":{"code":"temporarily_unavailable","message":"x"}},{"retry-after":"60"}]'
rest "$TMP/admin" "$NONCE_REST" -o "$TMP/r.json"
check "last known status" 'Last Known Status' "$TMP/r.json"
check "reason" 'temporarily unavailable' "$TMP/r.json"
rest "$TMP/admin" "$NONCE_REST" --data '{"force":true}' -o "$TMP/r.json"
check "Refresh waits for Retry-After" '"result":"wait"' "$TMP/r.json"
[ "$(calls /wp/v1/status)" = 1 ] && pass "one request despite Refresh" || fail "requests: $(calls /wp/v1/status)"

echo "--- 401 ends the connection"
$WP eval '$s = get_option( "uptimewire_status" ); $s["retry_at"] = 0; $s["attempted_at"] -= 600; update_option( "uptimewire_status", $s, false );' >/dev/null
fake '[401,{"error":{"code":"connection_ended","message":"x"}},{"www-authenticate":"Bearer"}]'
rest "$TMP/admin" "$NONCE_REST" -o "$TMP/r.json"
check "redirect to Overview" '"redirect":' "$TMP/r.json"
page "$TMP/admin" "$TMP/ended.html" >/dev/null
check "reconnection offered" 'Reconnection Required' "$TMP/ended.html"
[ "$($WP option get uptimewire_status 2>/dev/null | wc -c)" = 0 ] && pass "kept status removed" || fail "kept status left"

echo "--- logs"
logged=$(grep -v "phar:///usr/local/bin/wp/" /var/www/html/wp-content/debug.log 2>/dev/null)
ours=$(echo "$logged" | grep -cE "plugins/uptimewire|Fatal")
if [ "$ours" != 0 ]; then fail "debug.log has lines from the plugin:"; echo "$logged" | grep -E "plugins/uptimewire|Fatal" | head -20
else pass "debug.log: nothing from the plugin"; fi
absent "no credential in the debug log" "$TOKEN" /var/www/html/wp-content/debug.log

rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

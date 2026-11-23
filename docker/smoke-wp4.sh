#!/usr/bin/env bash
# WP-4 checks (Dashboard widget, Site Health, Settings) through real HTTP requests, inside a stand container after install-wp.sh.
# UptimeWire is played by docker/fake-api.php (a must-use plugin of the stand).
#   smoke-wp4.sh <port>
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
dash() { $CURL -b "$1" -o "$2" -w '%{http_code}' "$BASE/wp-admin/index.php"; }
widget() { # jar nonce [extra curl args]
	local jar="$1" nonce="$2"; shift 2
	$CURL -b "$jar" -H "X-WP-Nonce: $nonce" -H 'Content-Type: application/json' -X POST --data '{"view":"widget"}' "$@" "$BASE/?rest_route=/uptimewire/v1/status-refresh"
}
$WP user meta delete 1 metaboxhidden_dashboard >/dev/null 2>&1

echo "--- Dashboard widget: never waits for UptimeWire"
fake "$(status_json up)"
$WP option patch insert uwtest_fake_api delay 5 >/dev/null
start=$(date +%s%N)
[ "$(dash "$TMP/admin" "$TMP/dash.html")" = 200 ] && pass "dashboard 200" || fail "dashboard"
ms=$(( ($(date +%s%N) - start) / 1000000 ))
[ "$ms" -lt 3000 ] && pass "dashboard rendered in ${ms} ms while UptimeWire would take 5 s" || fail "dashboard took ${ms} ms"
$WP option patch delete uwtest_fake_api delay >/dev/null
check "widget title" 'UptimeWire — Website Status' "$TMP/dash.html"
check "widget container" 'id="uptimewire-widget"' "$TMP/dash.html"
check "loading state first" 'Loading Your Website Status' "$TMP/dash.html"
check "due for the script" 'data-due="1"' "$TMP/dash.html"
check "widget style" 'uptimewire/assets/widget.css' "$TMP/dash.html"
check "refresh script" 'uptimewire/assets/dashboard.js' "$TMP/dash.html"
check "script set for the widget" '"container":"uptimewire-widget"' "$TMP/dash.html"
absent "no plugin screen styles" 'uptimewire/assets/admin.css' "$TMP/dash.html"
absent "no connect script" 'uptimewire/assets/connect.js' "$TMP/dash.html"
absent "no credential in the page" 'uwp_' "$TMP/dash.html"
check "Screen Options can hide it" 'id="uptimewire_status-hide"' "$TMP/dash.html"
[ "$(calls /wp/v1/status)" = 0 ] && pass "no request while rendering" || fail "requested while rendering"
NONCE=$(rest_nonce "$TMP/dash.html")
[ -n "$NONCE" ] && pass "nonce found" || fail "nonce not found"

echo "--- Dashboard widget: not for editors"
dash "$TMP/editor" "$TMP/dash-editor.html" >/dev/null
absent "editor: no widget" 'uptimewire-widget' "$TMP/dash-editor.html"
absent "editor: no style" 'uptimewire/assets/widget.css' "$TMP/dash-editor.html"
absent "editor: no script" 'uptimewire/assets/dashboard.js' "$TMP/dash-editor.html"
absent "editor: no account number" 'ACC-00001000' "$TMP/dash-editor.html"

echo "--- Assets stay off unrelated screens"
$CURL -b "$TMP/admin" -o "$TMP/posts.html" "$BASE/wp-admin/edit.php"
absent "posts: no widget style" 'uptimewire/assets/widget.css' "$TMP/posts.html"
absent "posts: no script" 'uptimewire/assets/dashboard.js' "$TMP/posts.html"
absent "posts: no plugin styles" 'uptimewire/assets/admin.css' "$TMP/posts.html"

echo "--- Widget refresh: CSRF, capability, one read"
code=$($CURL -b "$TMP/admin" -X POST --data '{"view":"widget"}' -H 'Content-Type: application/json' -o "$TMP/r.json" -w '%{http_code}' "$BASE/?rest_route=/uptimewire/v1/status-refresh")
[ "$code" = 401 ] && pass "without nonce: 401" || fail "without nonce: $code"
code=$(widget "$TMP/editor" "$NONCE" -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 403 ] && pass "editor: 403" || fail "editor: $code"
[ "$(calls /wp/v1/status)" = 0 ] && pass "refused requests asked nothing" || fail "refused requests reached UptimeWire"
code=$(widget "$TMP/admin" "$NONCE" -D "$TMP/h.txt" -o "$TMP/r.json" -w '%{http_code}')
[ "$code" = 200 ] && pass "refresh 200" || fail "refresh: $code $(head -c 300 "$TMP/r.json")"
grep -qiE '^cache-control:.*no-store' "$TMP/h.txt" && pass "answer not cached" || fail "cache header"
check "online in the widget" 'Your Website Is Online' "$TMP/r.json"
check "widget markup, not the Overview" 'uptimewire-widget-title' "$TMP/r.json"
check "uptime" '99.97% in the last 30 days' "$TMP/r.json"
check "SSL" 'Valid \\u2014 79 days remaining\|Valid — 79 days remaining' "$TMP/r.json"
absent "no credential in the answer" 'uwp_' "$TMP/r.json"
widget "$TMP/admin" "$NONCE" -o "$TMP/r2.json"
check "second read reuses the copy" '"result":"fresh"' "$TMP/r2.json"
page "$TMP/admin" "$TMP/overview.html" >/dev/null
check "Overview shows the same copy" 'Your Website Is Online' "$TMP/overview.html"
check "Overview not due" 'data-due="0"' "$TMP/overview.html"
[ "$(calls /wp/v1/status)" = 1 ] && pass "one request for widget, widget again and Overview" || fail "requests: $(calls /wp/v1/status)"
dash "$TMP/admin" "$TMP/dash2.html" >/dev/null
check "dashboard from the copy" 'Your Website Is Online' "$TMP/dash2.html"
check "dashboard not due" 'data-due="0"' "$TMP/dash2.html"

echo "--- Site Health"
code=$($CURL -b "$TMP/admin" -o "$TMP/health.html" -w '%{http_code}' "$BASE/wp-admin/site-health.php")
[ "$code" = 200 ] && pass "site health 200" || fail "site health: $code"
check "monitoring test" 'UptimeWire is monitoring your website, and it is online' "$TMP/health.html"
check "SSL test" 'Your SSL certificate is valid' "$TMP/health.html"
check "access test" 'UptimeWire can reach your website' "$TMP/health.html"
absent "no credential" 'uwp_' "$TMP/health.html"
$CURL -b "$TMP/admin" -o "$TMP/info.html" "$BASE/wp-admin/site-health.php?tab=debug"
check "Info section" 'health-check-accordion-block-uptimewire' "$TMP/info.html"
check "Info: account number" 'ACC-00001000' "$TMP/info.html"
check "Info: connection" 'Connection Established' "$TMP/info.html"
absent "Info: no credential" 'uwp_' "$TMP/info.html"
absent "Info: no device code" 'uwd_' "$TMP/info.html"
perl -0ne 'print $1 if /(id="health-check-accordion-block-uptimewire".*?<\/table>)/s' "$TMP/info.html" > "$TMP/info-ours.html"
check "our section cut out" 'Plugin version' "$TMP/info-ours.html"
absent "our section: no email address" '@' "$TMP/info-ours.html"
$CURL -b "$TMP/editor" -o "$TMP/info-editor.html" "$BASE/wp-admin/site-health.php?tab=debug"
absent "editor: no Info section" 'health-check-accordion-block-uptimewire' "$TMP/info-editor.html"
[ "$(calls /wp/v1/status)" = 1 ] && pass "Site Health asked nothing" || fail "requests: $(calls /wp/v1/status)"

echo "--- Settings"
[ "$(page "$TMP/admin" "$TMP/settings.html" uptimewire-settings)" = 200 ] && pass "settings 200" || fail "settings"
check "account number" 'ACC-00001000' "$TMP/settings.html"
check "alerts ready" 'Alerts Are Ready' "$TMP/settings.html"
check "channels" 'Configured Notification Channels' "$TMP/settings.html"
check "plans link" 'View Plans &amp; Features' "$TMP/settings.html"
check "Site Health link" 'site-health.php?tab=debug' "$TMP/settings.html"
absent "no credential" 'uwp_' "$TMP/settings.html"
code=$(page "$TMP/editor" "$TMP/settings-editor.html" uptimewire-settings)
[ "$code" = 403 ] && pass "editor: 403" || fail "editor settings: $code"
absent "editor: no account number" 'ACC-00001000' "$TMP/settings-editor.html"

echo "--- Revoked: the widget says so, the Dashboard stays"
$WP eval '$s = get_option( "uptimewire_status" ); $s["retry_at"] = 0; $s["attempted_at"] -= 600; $s["fetched_at"] -= 600; update_option( "uptimewire_status", $s, false );' >/dev/null
fake '[401,{"error":{"code":"connection_ended","message":"x"}},{"www-authenticate":"Bearer"}]'
widget "$TMP/admin" "$NONCE" -o "$TMP/r.json"
absent "no redirect from the Dashboard" '"redirect"' "$TMP/r.json"
check "Reconnect to UptimeWire" 'Reconnect to UptimeWire' "$TMP/r.json"
dash "$TMP/admin" "$TMP/dash3.html" >/dev/null
check "dashboard: Reconnect to UptimeWire" 'Reconnect to UptimeWire' "$TMP/dash3.html"
absent "dashboard: no refresh script after the end" 'uptimewire/assets/dashboard.js' "$TMP/dash3.html"
$CURL -b "$TMP/admin" -o "$TMP/health2.html" "$BASE/wp-admin/site-health.php"
check "Site Health: connection no longer active" 'The UptimeWire connection is no longer active' "$TMP/health2.html"
absent "Site Health: no monitoring test" 'uptimewire_monitoring' "$TMP/health2.html"

echo "--- Not connected: one neutral line"
$WP eval 'delete_option( "uptimewire_connection" ); delete_option( "uptimewire_status" );' >/dev/null
dash "$TMP/admin" "$TMP/dash4.html" >/dev/null
check "not connected" 'UptimeWire Is Not Connected' "$TMP/dash4.html"
check "set up" 'Set Up Monitoring' "$TMP/dash4.html"
absent "no refresh script" 'uptimewire/assets/dashboard.js' "$TMP/dash4.html"
$CURL -b "$TMP/admin" -o "$TMP/health3.html" "$BASE/wp-admin/site-health.php"
absent "Site Health: nothing before connecting" 'uptimewire_' "$TMP/health3.html"

echo "--- logs"
logged=$(grep -v "phar:///usr/local/bin/wp/" /var/www/html/wp-content/debug.log 2>/dev/null)
ours=$(echo "$logged" | grep -cE "plugins/uptimewire|Fatal")
if [ "$ours" != 0 ]; then fail "debug.log has lines from the plugin:"; echo "$logged" | grep -E "plugins/uptimewire|Fatal" | head -20
else pass "debug.log: nothing from the plugin"; fi
absent "no credential in the debug log" "$TOKEN" /var/www/html/wp-content/debug.log

rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

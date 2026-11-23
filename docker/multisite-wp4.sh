#!/usr/bin/env bash
# WP-4 on a multisite network, inside a stand container after multisite-connect.sh:
# sites /two/ and /three/ each keep their own status; the widget, Site Health
# and the refresh route of one site never show or read another site's data.
#   multisite-wp4.sh <port>
set -uo pipefail
PORT="$1"
BASE="http://localhost:$PORT"
WP="php -d display_errors=0 -d error_reporting=0 /usr/local/bin/wp --allow-root --path=/var/www/html"
TWO="--url=localhost:$PORT/two/"
THREE="--url=localhost:$PORT/three/"
CURL="curl -s --connect-to localhost:$PORT:127.0.0.1:80"
TMP=$(mktemp -d)
FAILS=0
TOKEN=uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
check() { if grep -q -- "$2" "$3"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
absent() { if grep -q -- "$2" "$3"; then fail "$1 (found: $2)"; else pass "$1"; fi; }
login() { # user password jar
	$CURL -c "$3" -b "$3" -o /dev/null "$BASE/wp-login.php"
	$CURL -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data "testcookie=1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}
status_json() { # site-url account-label
	now=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)
	cat <<JSON
[200,{"data":{"connection":{"id":"0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b","state":"active","connected_at":"2026-11-23T08:37:39+00:00","account_label":"$2"},
"site":{"url":"$1","created_at":"2026-08-01T00:00:00+00:00","state":"up","paused":false,"awaiting_email_confirmation":false,"interval_minutes":5,"problem":null,"down_since":null,"last_checked_at":"$now","last_success_at":"$now","last_location":"Germany","average_response_ms_24h":412,"blocked":null},
"uptime":[{"window":"24h","from":null,"to":"$now","measured_seconds":0,"downtime_seconds":0,"percent":null,"history_limited":false},{"window":"7d","from":null,"to":"$now","measured_seconds":0,"downtime_seconds":0,"percent":null,"history_limited":false},{"window":"30d","from":null,"to":"$now","measured_seconds":0,"downtime_seconds":0,"percent":null,"history_limited":false}],
"checks":{"ssl":{"state":"ok","checked_at":"$now","expires_at":null,"days_left":null,"warning":null,"error":null},"domain":{"state":"unknown","checked_at":null,"expires_at":null,"days_left":null,"expiry_known":false,"warning":null,"error":null},"dns":{"state":"ok","checked_at":"$now","changed":false,"open_changes":0,"last_change_at":null}},
"events":[],"alerts":{"ready":true,"channels":["email"],"email_pending":false},
"links":{"site":"https://app.uptimewire.com/monitors/x/view","alerts":"https://app.uptimewire.com/user/profile/notifications","plans":"https://app.uptimewire.com/subscription-plans"}},
"meta":{"server_time":"$now"}}]
JSON
}

cd /var/www/html
$WP core is-installed --network 2>/dev/null || { echo "Run multisite-connect.sh first."; exit 1; }
mkdir -p wp-content/mu-plugins
cp /src/docker/fake-api.php wp-content/mu-plugins/uw-fake-api.php
chown -R www-data:www-data wp-content

# Site three gets its own connection and account; the main site stays unconnected.
$WP option update uptimewire_site_url "https://three.customer-site.com/" --autoload=no $THREE >/dev/null
$WP eval 'UptimeWire\Connection::save( "'$TOKEN'", array( "id" => "0196a3b2-bbbb-7e3f-8a9b-0c1d2e3f4a5b", "connected_at" => "2026-11-23T08:37:39+00:00", "site_url" => "https://three.customer-site.com", "local_url" => "https://three.customer-site.com/", "connected_by" => 1, "account_label" => "ACC-00000333" ) );' $THREE >/dev/null
$WP option update uwtest_fake_api "{\"/wp/v1/status\":[$(status_json https://two.customer-site.com ACC-00000222)]}" --format=json $TWO >/dev/null
$WP option update uwtest_fake_api "{\"/wp/v1/status\":[$(status_json https://three.customer-site.com ACC-00000333)]}" --format=json $THREE >/dev/null
[ "$($WP eval 'echo UptimeWire\Status::refresh( true );' $TWO)" = updated ] && pass "site two read its status" || fail "site two read"
[ "$($WP eval 'echo UptimeWire\Status::refresh( true );' $THREE)" = updated ] && pass "site three read its status" || fail "site three read"
: > wp-content/fake-api-log.txt

$WP user get threeadmin >/dev/null 2>&1 || $WP user create threeadmin threeadmin@example.com --role=administrator --user_pass=stand-three-pass $THREE >/dev/null
login admin stand-admin-pass "$TMP/super"
login threeadmin stand-three-pass "$TMP/three"

echo "--- each site's widget shows its own data"
$CURL -b "$TMP/super" -o "$TMP/two.html" "$BASE/two/wp-admin/index.php"
$CURL -b "$TMP/super" -o "$TMP/three.html" "$BASE/three/wp-admin/index.php"
$CURL -b "$TMP/super" -o "$TMP/main.html" "$BASE/wp-admin/index.php"
$CURL -b "$TMP/super" -o "$TMP/network.html" "$BASE/wp-admin/network/index.php"
check "two: online" 'Your Website Is Online' "$TMP/two.html"
check "three: online" 'Your Website Is Online' "$TMP/three.html"
check "main: not connected" 'UptimeWire Is Not Connected' "$TMP/main.html"
absent "network admin: no widget" 'uptimewire-widget' "$TMP/network.html"
$CURL -b "$TMP/super" -o "$TMP/info2.html" "$BASE/two/wp-admin/site-health.php?tab=debug"
$CURL -b "$TMP/super" -o "$TMP/info3.html" "$BASE/three/wp-admin/site-health.php?tab=debug"
$CURL -b "$TMP/super" -o "$TMP/infomain.html" "$BASE/wp-admin/site-health.php?tab=debug"
check "two Info: its account" 'ACC-00000222' "$TMP/info2.html"
absent "two Info: not three's account" 'ACC-00000333' "$TMP/info2.html"
check "three Info: its account" 'ACC-00000333' "$TMP/info3.html"
absent "three Info: not two's account" 'ACC-00000222' "$TMP/info3.html"
absent "main Info: no account" 'ACC-00000' "$TMP/infomain.html"
absent "main Info: not two's website" 'two.customer-site.com' "$TMP/infomain.html"

echo "--- an administrator of site three only"
$CURL -b "$TMP/three" -o "$TMP/t3.html" "$BASE/three/wp-admin/index.php"
check "own widget" 'Your Website Is Online' "$TMP/t3.html"
NONCE=$(perl -ne 'print $1 if /uptimewireDashboard = {.*?"nonce":"([0-9a-f]+)"/' "$TMP/t3.html")
[ -n "$NONCE" ] && pass "nonce found" || fail "nonce not found"
code=$($CURL -b "$TMP/three" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' -X POST --data '{"view":"widget","force":true}' -o "$TMP/r.json" -w '%{http_code}' "$BASE/two/?rest_route=/uptimewire/v1/status-refresh")
[ "$code" = 403 ] && pass "site two's refresh route: 403" || fail "site two's refresh: $code $(head -c 200 "$TMP/r.json")"
absent "nothing of site two in the answer" 'ACC-00000222' "$TMP/r.json"
code=$($CURL -b "$TMP/three" -o "$TMP/t2.html" -w '%{http_code}' "$BASE/two/wp-admin/admin.php?page=uptimewire-settings")
absent "site two's Settings: no data" 'ACC-00000222' "$TMP/t2.html"
$CURL -b "$TMP/three" -o "$TMP/t2i.html" "$BASE/two/wp-admin/site-health.php?tab=debug"
absent "site two's Site Health: no data" 'ACC-00000222' "$TMP/t2i.html"
code=$($CURL -b "$TMP/three" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' -X POST --data '{"view":"widget"}' -o "$TMP/r3.json" -w '%{http_code}' "$BASE/three/?rest_route=/uptimewire/v1/status-refresh")
[ "$code" = 200 ] && pass "own refresh route: 200" || fail "own refresh: $code"
absent "own answer: not site two" 'two.customer-site.com' "$TMP/r3.json"
[ "$(grep -c '^/wp/v1/status' wp-content/fake-api-log.txt)" = 0 ] && pass "no reads (own copy is fresh, the other refused)" || fail "reads: $(cat wp-content/fake-api-log.txt)"

$WP option delete uwtest_fake_api $TWO >/dev/null
$WP option delete uwtest_fake_api $THREE >/dev/null
rm -f wp-content/mu-plugins/uw-fake-api.php
rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

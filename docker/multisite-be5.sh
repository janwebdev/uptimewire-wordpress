#!/usr/bin/env bash
# BE-5 on a multisite network, inside a stand container after multisite-connect.sh:
# site /two/ gets a status with the email domain and the scheduled job, site /three/
# a status of a server before BE-5. Each shows only its own, and three shows none.
#   multisite-be5.sh <port>
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
status_json() { # site-url account-label extra-checks extra-links
	now=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)
	cat <<JSON
[200,{"data":{"connection":{"id":"0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b","state":"active","connected_at":"2026-11-23T08:37:39+00:00","account_label":"$2"},
"site":{"url":"$1","created_at":"2026-08-01T00:00:00+00:00","state":"up","paused":false,"awaiting_email_confirmation":false,"interval_minutes":5,"problem":null,"down_since":null,"last_checked_at":"$now","last_success_at":"$now","last_location":"Germany","average_response_ms_24h":412,"blocked":null},
"uptime":[{"window":"24h","from":null,"to":"$now","measured_seconds":0,"downtime_seconds":0,"percent":null,"history_limited":false},{"window":"7d","from":null,"to":"$now","measured_seconds":0,"downtime_seconds":0,"percent":null,"history_limited":false},{"window":"30d","from":null,"to":"$now","measured_seconds":0,"downtime_seconds":0,"percent":null,"history_limited":false}],
"checks":{"ssl":{"state":"ok","checked_at":"$now","expires_at":null,"days_left":null,"warning":null,"error":null},"domain":{"state":"unknown","checked_at":null,"expires_at":null,"days_left":null,"expiry_known":false,"warning":null,"error":null},"dns":{"state":"ok","checked_at":"$now","changed":false,"open_changes":0,"last_change_at":null}$3},
"events":[],"alerts":{"ready":true,"channels":["email"],"email_pending":false},
"links":{"site":"https://app.uptimewire.com/monitors/x/view","alerts":"https://app.uptimewire.com/user/profile/notifications","plans":"https://app.uptimewire.com/subscription-plans"$4}},
"meta":{"server_time":"$now"}}]
JSON
}
BE5=',"mail":{"state":"problem","items":[{"key":"mx","state":"problem","reason":"mx.missing"},{"key":"spf","state":"problem","reason":"spf.missing"}],"checked_at":null},"scheduled_job":{"state":"late","last_call_at":"2026-11-22T10:00:00+00:00","interval_seconds":3600,"grace_seconds":900}'
JOB_LINK=',"scheduled_job":"https://app.uptimewire.com/monitors/x/view#scheduled-job"'

cd /var/www/html
$WP core is-installed --network 2>/dev/null || { echo "Run multisite-connect.sh first."; exit 1; }
mkdir -p wp-content/mu-plugins
cp /src/docker/fake-api.php wp-content/mu-plugins/uw-fake-api.php
# multisite-wp5.sh ends by uninstalling the plugin: bring it back
[ -d wp-content/plugins/uptimewire ] || cp -r /src/build/uptimewire wp-content/plugins/uptimewire
chown -R www-data:www-data wp-content
$WP plugin activate uptimewire --network >/dev/null 2>&1

$WP option update uptimewire_site_url "https://two.customer-site.com/" --autoload=no $TWO >/dev/null
$WP eval 'UptimeWire\Connection::save( "'$TOKEN'", array( "id" => "0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b", "connected_at" => "2026-11-23T08:37:39+00:00", "site_url" => "https://two.customer-site.com", "local_url" => "https://two.customer-site.com/", "connected_by" => 1, "account_label" => "ACC-00000222" ) );' $TWO >/dev/null
$WP option update uptimewire_site_url "https://three.customer-site.com/" --autoload=no $THREE >/dev/null
$WP eval 'UptimeWire\Connection::save( "'$TOKEN'", array( "id" => "0196a3b2-bbbb-7e3f-8a9b-0c1d2e3f4a5b", "connected_at" => "2026-11-23T08:37:39+00:00", "site_url" => "https://three.customer-site.com", "local_url" => "https://three.customer-site.com/", "connected_by" => 1, "account_label" => "ACC-00000333" ) );' $THREE >/dev/null
$WP option update uwtest_fake_api "{\"/wp/v1/status\":[$(status_json https://two.customer-site.com ACC-00000222 "$BE5" "$JOB_LINK")]}" --format=json $TWO >/dev/null
$WP option update uwtest_fake_api "{\"/wp/v1/status\":[$(status_json https://three.customer-site.com ACC-00000333 '' '')]}" --format=json $THREE >/dev/null
$WP eval 'UptimeWire\Status::forget();' $TWO >/dev/null
$WP eval 'UptimeWire\Status::forget();' $THREE >/dev/null
[ "$($WP eval 'echo UptimeWire\Status::refresh( true );' $TWO)" = updated ] && pass "site two read its status" || fail "site two read"
[ "$($WP eval 'echo UptimeWire\Status::refresh( true );' $THREE)" = updated ] && pass "site three read its status" || fail "site three read"

login admin stand-admin-pass "$TMP/super"

echo "--- site two: both checks, everywhere"
$CURL -b "$TMP/super" -o "$TMP/two.html" "$BASE/two/wp-admin/admin.php?page=uptimewire"
$CURL -b "$TMP/super" -o "$TMP/two-dash.html" "$BASE/two/wp-admin/index.php"
$CURL -b "$TMP/super" -o "$TMP/two-health.html" "$BASE/two/wp-admin/site-health.php"
$CURL -b "$TMP/super" -o "$TMP/two-info.html" "$BASE/two/wp-admin/site-health.php?tab=debug"
$CURL -b "$TMP/super" -o "$TMP/two-settings.html" "$BASE/two/wp-admin/admin.php?page=uptimewire-settings"
check "Overview: email card" 'Email Domain</h3>' "$TMP/two.html"
check "Overview: job card" 'Scheduled Job</h3>' "$TMP/two.html"
check "Overview: job link" 'monitors/x/view#scheduled-job' "$TMP/two.html"
check "widget: one line" 'email domain, scheduled job' "$TMP/two-dash.html"
check "Site Health: email" 'email setup has a problem' "$TMP/two-health.html"
check "Site Health: job" 'Your scheduled job is late' "$TMP/two-health.html"
check "Info: email" 'Email domain' "$TMP/two-info.html"
check "Settings: job row" '<strong>Scheduled job:</strong>' "$TMP/two-settings.html"
absent "Overview: no PHP notice" 'Warning</b>:' "$TMP/two.html"

echo "--- site three: a server before BE-5, nothing of two"
$CURL -b "$TMP/super" -o "$TMP/three.html" "$BASE/three/wp-admin/admin.php?page=uptimewire"
$CURL -b "$TMP/super" -o "$TMP/three-dash.html" "$BASE/three/wp-admin/index.php"
$CURL -b "$TMP/super" -o "$TMP/three-health.html" "$BASE/three/wp-admin/site-health.php"
$CURL -b "$TMP/super" -o "$TMP/three-info.html" "$BASE/three/wp-admin/site-health.php?tab=debug"
check "Overview: online" 'Your Website Is Online' "$TMP/three.html"
absent "Overview: no email card" 'Email Domain' "$TMP/three.html"
absent "Overview: no job card" 'Scheduled Job' "$TMP/three.html"
absent "Overview: not two's link" 'scheduled-job' "$TMP/three.html"
absent "widget: no line" 'scheduled job' "$TMP/three-dash.html"
absent "Site Health: nothing" 'scheduled job' "$TMP/three-health.html"
absent "Info: nothing" 'Email domain' "$TMP/three-info.html"
absent "Overview: no PHP notice" 'Warning</b>:' "$TMP/three.html"

$WP option delete uwtest_fake_api $TWO >/dev/null
$WP option delete uwtest_fake_api $THREE >/dev/null
rm -f wp-content/mu-plugins/uw-fake-api.php
rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

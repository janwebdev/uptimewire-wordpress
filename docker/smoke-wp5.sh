#!/usr/bin/env bash
# WP-5 checks (disconnect, wp-config.php credential, copies, deactivation, uninstall) through real
# HTTP requests and WP-CLI, inside a single-site stand container after install-wp.sh.
# UptimeWire is played by docker/fake-api.php (a must-use plugin of the stand).
#   smoke-wp5.sh <port>
# Ends by uninstalling the plugin (its files are deleted): copy it in again afterwards.
# Prints PASS/FAIL lines; exits non-zero when anything failed.
set -uo pipefail
PORT="$1"
BASE="http://localhost:$PORT"
WP="php -d display_errors=0 -d error_reporting=0 /usr/local/bin/wp --allow-root --path=/var/www/html"
CURL="curl -s --connect-to localhost:$PORT:127.0.0.1:80 --connect-to copy.localhost:$PORT:127.0.0.1:80"
TMP=$(mktemp -d)
FAILS=0
LOG=/var/www/html/wp-content/fake-api-log.txt
TOKEN=uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
TOKEN2=uwp_abababababababababababababababababababababababababababababababab

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
check() { if grep -q -- "$2" "$3"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
absent() { if grep -q -- "$2" "$3"; then fail "$1 (found: $2)"; else pass "$1"; fi; }
same() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$3', got '$2')"; fi; }
calls() { grep -c "^$1 " "$LOG" 2>/dev/null || true; }
login() { # base user password jar
	$CURL -c "$4" -b "$4" -o /dev/null "$1/wp-login.php"
	$CURL -c "$4" -b "$4" -o /dev/null --data-urlencode "log=$2" --data-urlencode "pwd=$3" \
		--data "testcookie=1&redirect_to=$1/wp-admin/" "$1/wp-login.php"
}
get() { $CURL -b "$1" -o "$2" -w '%{http_code}' "$3"; }
form_nonce() { perl -0ne 'print $1 if /value="'"$1"'">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)"/' "$2"; }
post() { $CURL -b "$1" -o "$TMP/post.html" -w '%{http_code} %{redirect_url}' --data "$3" "$2/wp-admin/admin-post.php"; }
fake() { # JSON object of answers per path
	$WP option update uwtest_fake_api "$1" --format=json >/dev/null; : > "$LOG"; chmod 666 "$LOG"
}
connection_json() { # site-url
	echo "[200,{\"data\":{\"id\":\"0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b\",\"connected_at\":\"2026-11-23T08:37:39+00:00\",\"account_label\":\"ACC-00001000\",\"site\":{\"url\":\"$1\"}}}]"
}
connect() { # stores a connection made on this WordPress address
	$WP eval 'UptimeWire\Connection::save( "'"$TOKEN"'", UptimeWire\Connection::identity() + array( "id" => "0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b", "connected_at" => "2026-11-23T08:37:39+00:00", "site_url" => "https://www.customer-site.com", "local_url" => "https://www.customer-site.com/", "connected_by" => 1, "account_label" => "ACC-00001000" ) );' >/dev/null
}
reset_plugin() {
	$WP eval 'global $wpdb; foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"%uptimewire%\"" ) as $o ) { delete_option( $o ); }' >/dev/null
	$WP option update uptimewire_site_url "https://www.customer-site.com/" --autoload=no >/dev/null
}
# Apache keeps compiled PHP files for up to 2 seconds (OPcache): pages read the changed wp-config.php only after that.
cfg() { $WP config "$@" >/dev/null; sleep 3; }
option_exists() { $WP option get "$1" >/dev/null 2>&1 && echo yes || echo no; }
# The image has no mysql client for "wp db query": the plugin's rows are read through WordPress.
plugin_values() { $WP eval 'global $wpdb; echo implode( "\n", $wpdb->get_col( "SELECT CONCAT( option_name, \"=\", option_value ) FROM {$wpdb->options} WHERE option_name LIKE \"%uptimewire%\"" ) );'; }
rows() { plugin_values | grep -c . ; }
no_token_in() { # label files...
	local label="$1"; shift
	if grep -l -- "$TOKEN" "$@" >/dev/null 2>&1 || grep -l -- "$TOKEN2" "$@" >/dev/null 2>&1; then fail "$label: credential found"; else pass "$label: no credential"; fi
}

cd /var/www/html
$WP config set WP_DEBUG true --raw >/dev/null
$WP config set WP_DEBUG_LOG true --raw >/dev/null
$WP config set WP_DEBUG_DISPLAY false --raw >/dev/null
$WP config delete UPTIMEWIRE_TOKEN >/dev/null 2>&1
mkdir -p wp-content/mu-plugins
cp /src/docker/fake-api.php wp-content/mu-plugins/uw-fake-api.php
rm -f wp-content/debug.log
$WP user get editor >/dev/null 2>&1 || $WP user create editor editor@example.com --role=editor --user_pass=stand-editor-pass >/dev/null
$WP plugin is-active uptimewire || $WP plugin activate uptimewire >/dev/null
reset_plugin
chown -R www-data:www-data wp-content
login "$BASE" admin stand-admin-pass "$TMP/admin"
login "$BASE" editor stand-editor-pass "$TMP/editor"

echo "--- Disconnect: confirmation, permissions, nonce"
connect
fake '{"/wp/v1/revoke":[[200,{}]]}'
get "$TMP/admin" "$TMP/settings.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings" >/dev/null
check "Settings offers Disconnect" 'uptimewire_confirm=disconnect' "$TMP/settings.html"
absent "no Stop Monitoring wording" 'Stop Monitoring' "$TMP/settings.html"
get "$TMP/admin" "$TMP/confirm.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings&uptimewire_confirm=disconnect" >/dev/null
check "confirmation title" 'Disconnect UptimeWire?' "$TMP/confirm.html"
check "monitoring continues" 'UptimeWire will continue monitoring your website and sending configured alerts.' "$TMP/confirm.html"
check "account unchanged" 'Your UptimeWire account, monitoring history, and website settings will remain unchanged.' "$TMP/confirm.html"
same "showing the confirmation asks nothing" "$(calls /wp/v1/revoke)" 0
NONCE=$(form_nonce uptimewire_disconnect "$TMP/confirm.html")
[ -n "$NONCE" ] && pass "disconnect nonce found" || fail "disconnect nonce not found"
same "editor: Settings refused" "$(get "$TMP/editor" "$TMP/e.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings")" 403
same "editor: disconnect refused" "$(post "$TMP/editor" "$BASE" "action=uptimewire_disconnect&_wpnonce=$NONCE" | cut -d' ' -f1)" 403
same "signed out: disconnect refused" "$($CURL -o /dev/null -w '%{http_code}' --data "action=uptimewire_disconnect&_wpnonce=$NONCE" "$BASE/wp-admin/admin-post.php")" 400
same "admin, wrong nonce: refused" "$(post "$TMP/admin" "$BASE" "action=uptimewire_disconnect&_wpnonce=0123456789" | cut -d' ' -f1)" 403
same "nothing sent yet" "$(calls /wp/v1/revoke)" 0
same "connection still stored" "$(option_exists uptimewire_connection)" yes

echo "--- Disconnect: UptimeWire confirms"
result=$(post "$TMP/admin" "$BASE" "action=uptimewire_disconnect&_wpnonce=$NONCE")
case "$result" in *"uptimewire_notice=disconnected"*) pass "redirect with the disconnected notice" ;; *) fail "redirect: $result" ;; esac
same "one revoke request" "$(calls /wp/v1/revoke)" 1
grep -q "^/wp/v1/revoke {\"token\":\"$TOKEN\"}" "$LOG" && pass "revoke carried this site's credential in the form body" || fail "revoke body: $(cat "$LOG")"
same "connection forgotten" "$(option_exists uptimewire_connection)" no
same "kept status forgotten" "$(option_exists uptimewire_status)" no
get "$TMP/admin" "$TMP/after.html" "$BASE/wp-admin/admin.php?page=uptimewire&uptimewire_notice=disconnected" >/dev/null
check "back on the welcome screen" 'Keep Your WordPress Website Online' "$TMP/after.html"
check "monitoring continues, said" 'UptimeWire keeps monitoring your website' "$TMP/after.html"
result=$(post "$TMP/admin" "$BASE" "action=uptimewire_disconnect&_wpnonce=$NONCE")
case "$result" in *"uptimewire_notice=disconnected"*) pass "a second disconnect is harmless" ;; *) fail "second disconnect: $result" ;; esac
same "no second revoke" "$(calls /wp/v1/revoke)" 1

echo "--- Disconnect: UptimeWire unavailable"
connect
fake '{"/wp/v1/revoke":[[503,{"error":{"code":"temporarily_unavailable","message":"x"}},{"retry-after":"30"}]]}'
result=$(post "$TMP/admin" "$BASE" "action=uptimewire_disconnect&_wpnonce=$NONCE")
case "$result" in *"uptimewire_notice=disconnect_unsure"*) pass "honest notice" ;; *) fail "redirect: $result" ;; esac
same "credential forgotten anyway" "$(option_exists uptimewire_connection)" no
get "$TMP/admin" "$TMP/unsure.html" "$BASE/wp-admin/admin.php?page=uptimewire&uptimewire_notice=disconnect_unsure" >/dev/null
check "not confirmed, said" 'UptimeWire didn’t confirm the disconnect' "$TMP/unsure.html"
check "where to remove it" 'https://app.uptimewire.com/user/profile/wordpress' "$TMP/unsure.html"
get "$TMP/admin" "$TMP/health.html" "$BASE/wp-admin/site-health.php" >/dev/null
check "Site Health reports it" 'the last disconnect' "$TMP/health.html"
plugin_values > "$TMP/db.txt"
check "the unconfirmed disconnect is remembered" 'uptimewire_unconfirmed=' "$TMP/db.txt"
no_token_in "database after an unconfirmed disconnect" "$TMP/db.txt"
fake '{}'
get "$TMP/admin" "$TMP/settings2.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings" >/dev/null
DNONCE=$(form_nonce uptimewire_dismiss_unconfirmed "$TMP/settings2.html")
post "$TMP/admin" "$BASE" "action=uptimewire_dismiss_unconfirmed&_wpnonce=$DNONCE" >/dev/null
same "notice closed" "$(option_exists uptimewire_unconfirmed)" no
same "no retry in the background" "$(calls /wp/v1/revoke)" 0

echo "--- UPTIMEWIRE_TOKEN in wp-config.php"
reset_plugin
cfg set UPTIMEWIRE_TOKEN "$TOKEN"
# The constant is proven only when UptimeWire names the WordPress address itself.
$WP option update uptimewire_site_url "$BASE/" --autoload=no >/dev/null
fake "{\"/wp/v1/connection\":[$(connection_json $BASE)],\"/wp/v1/revoke\":[[200,{}]]}"
get "$TMP/admin" "$TMP/c1.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings" >/dev/null
check "constant confirmed" 'Kept in wp-config.php (UPTIMEWIRE_TOKEN).' "$TMP/c1.html"
get "$TMP/admin" "$TMP/c2.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings&uptimewire_confirm=disconnect" >/dev/null
check "confirmation says the file stays" 'WordPress can’t edit that file' "$TMP/c2.html"
CNONCE=$(form_nonce uptimewire_disconnect "$TMP/c2.html")
post "$TMP/admin" "$BASE" "action=uptimewire_disconnect&_wpnonce=$CNONCE" >/dev/null
grep -q "^/wp/v1/revoke {\"token\":\"$TOKEN\"}" "$LOG" && pass "the constant's credential was ended" || fail "revoke: $(cat "$LOG")"
grep -q "define( 'UPTIMEWIRE_TOKEN'" wp-config.php && pass "wp-config.php untouched" || fail "wp-config.php changed"
: > "$LOG"
get "$TMP/admin" "$TMP/c3.html" "$BASE/wp-admin/admin.php?page=uptimewire" >/dev/null
get "$TMP/admin" "$TMP/c4.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings" >/dev/null
get "$TMP/admin" "$TMP/c5.html" "$BASE/wp-admin/index.php" >/dev/null
check "asked to remove the line" 'UPTIMEWIRE_TOKEN is still in wp-config.php' "$TMP/c3.html"
check "welcome screen with connect buttons" 'value="uptimewire_connect"' "$TMP/c3.html"
check "widget: not connected" 'UptimeWire Is Not Connected' "$TMP/c5.html"
same "the disconnected constant is never sent again" "$(wc -l < "$LOG")" 0
cfg set UPTIMEWIRE_TOKEN "$TOKEN2"
get "$TMP/admin" "$TMP/c6.html" "$BASE/wp-admin/admin.php?page=uptimewire" >/dev/null
grep -q "^/wp/v1/connection" "$LOG" && pass "a new constant value is used" || fail "new constant not used"
absent "no leftover notice for the new value" 'UPTIMEWIRE_TOKEN is still in wp-config.php' "$TMP/c6.html"
cfg delete UPTIMEWIRE_TOKEN
reset_plugin
cfg set UPTIMEWIRE_TOKEN "$TOKEN"
$WP eval 'update_option( UptimeWire\Connection::CONSTANT_OFF_OPTION, hash( "sha256", UPTIMEWIRE_TOKEN ), false );' >/dev/null
cfg delete UPTIMEWIRE_TOKEN
get "$TMP/admin" "$TMP/c7.html" "$BASE/wp-admin/admin.php?page=uptimewire" >/dev/null
same "the fingerprint goes when the line goes" "$(option_exists uptimewire_constant_off)" no
no_token_in "pages of the constant scenario" "$TMP"/c*.html

echo "--- A copy made with search-replace (another address, the same database)"
reset_plugin
connect
fake "{\"/wp/v1/connection\":[$(connection_json https://www.customer-site.com)],\"/wp/v1/status\":[[503,{}]],\"/wp/v1/revoke\":[[200,{}]]}"
$WP search-replace "localhost:$PORT" "copy.localhost:$PORT" --all-tables --quiet >/dev/null
COPY="http://copy.localhost:$PORT"
login "$COPY" admin stand-admin-pass "$TMP/copy"
get "$TMP/copy" "$TMP/k1.html" "$COPY/wp-admin/admin.php?page=uptimewire" >/dev/null
get "$TMP/copy" "$TMP/k2.html" "$COPY/wp-admin/index.php" >/dev/null
get "$TMP/copy" "$TMP/k3.html" "$COPY/wp-admin/admin.php?page=uptimewire-settings" >/dev/null
get "$TMP/copy" "$TMP/k4.html" "$COPY/wp-admin/site-health.php?tab=debug" >/dev/null
check "copy screen" 'This WordPress Site Is a Copy' "$TMP/k1.html"
check "local removal offered" 'Remove Connection From This Copy' "$TMP/k1.html"
check "widget says copy" 'This WordPress Site Is a Copy' "$TMP/k2.html"
absent "no Disconnect on the copy" 'uptimewire_confirm=disconnect' "$TMP/k3.html"
absent "no account number on the copy" 'ACC-00001000' "$TMP/k1.html"
absent "no account number in the copy's Info" 'ACC-00001000' "$TMP/k4.html"
same "the copy sends nothing with the original's credential" "$(wc -l < "$LOG")" 0
KNONCE=$(form_nonce uptimewire_remove_local "$TMP/k1.html")
result=$(post "$TMP/copy" "$COPY" "action=uptimewire_remove_local&_wpnonce=$KNONCE")
case "$result" in *"uptimewire_notice=copy_removed"*) pass "removed from the copy" ;; *) fail "remove: $result" ;; esac
same "the original's connection is not ended" "$(calls /wp/v1/revoke)" 0
same "copy forgot it" "$(option_exists uptimewire_connection)" no
$WP search-replace "copy.localhost:$PORT" "localhost:$PORT" --all-tables --quiet >/dev/null
no_token_in "pages of the copy" "$TMP"/k*.html

echo "--- The website to monitor is locked while connected"
reset_plugin
get "$TMP/admin" "$TMP/welcome.html" "$BASE/wp-admin/admin.php?page=uptimewire" >/dev/null
UNONCE=$(form_nonce uptimewire_save_site_url "$TMP/welcome.html")
connect
result=$(post "$TMP/admin" "$BASE" "action=uptimewire_save_site_url&_wpnonce=$UNONCE&uptimewire_site_url=https%3A%2F%2Fwww.other-site.com%2F")
case "$result" in *"uptimewire_notice=site_url_locked"*) pass "change refused while connected" ;; *) fail "save: $result" ;; esac
same "address unchanged" "$($WP option get uptimewire_site_url)" "https://www.customer-site.com/"

echo "--- Deactivation keeps the connection"
reset_plugin
connect
fake '{"/wp/v1/revoke":[[200,{}]]}'
$WP plugin deactivate uptimewire >/dev/null
same "deactivated: connection kept" "$(option_exists uptimewire_connection)" yes
same "deactivated: nothing sent" "$(wc -l < "$LOG")" 0
same "no scheduled task of the plugin" "$($WP cron event list --fields=hook --format=csv | grep -c uptimewire)" 0
$WP plugin activate uptimewire >/dev/null
same "reactivated: still connected, no reconnect" "$($WP eval 'echo UptimeWire\State::current();')" connection_established
same "reactivated: same credential" "$($WP eval 'echo UptimeWire\Connection::token() === "'"$TOKEN"'" ? "same" : "other";')" same

echo "--- Uninstall: own connection ended, data removed, nothing else"
$WP option update other_plugin_keep 1 >/dev/null
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null
grep -q "^/wp/v1/revoke {\"token\":\"$TOKEN\"}" "$LOG" && pass "uninstall ended the connection" || fail "uninstall revoke: $(cat "$LOG")"
same "no plugin rows left" "$(rows)" 0
same "other plugin's option kept" "$($WP option get other_plugin_keep)" 1
$WP option delete other_plugin_keep >/dev/null

cp -r /src/build/uptimewire wp-content/plugins/uptimewire && chown -R www-data:www-data wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null
reset_plugin
connect
fake '{}'
start=$(date +%s)
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null && pass "uninstall works while UptimeWire can't be reached" || fail "uninstall failed"
[ $(( $(date +%s) - start )) -lt 30 ] && pass "and doesn't hang" || fail "uninstall took too long"
same "data removed anyway" "$(rows)" 0

cp -r /src/build/uptimewire wp-content/plugins/uptimewire && chown -R www-data:www-data wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null
reset_plugin
connect
$WP search-replace "localhost:$PORT" "copy.localhost:$PORT" --all-tables --quiet >/dev/null
fake '{"/wp/v1/revoke":[[200,{}]]}'
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null
same "uninstall on a copy ends nothing" "$(calls /wp/v1/revoke)" 0
$WP search-replace "copy.localhost:$PORT" "localhost:$PORT" --all-tables --quiet >/dev/null

cp -r /src/build/uptimewire wp-content/plugins/uptimewire && chown -R www-data:www-data wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null
reset_plugin
$WP option update uptimewire_site_url "$BASE/" --autoload=no >/dev/null
cfg set UPTIMEWIRE_TOKEN "$TOKEN"
fake "{\"/wp/v1/connection\":[$(connection_json $BASE)],\"/wp/v1/revoke\":[[200,{}]]}"
$WP eval 'UptimeWire\Connection::check_health( true ); echo UptimeWire\State::current();' >/dev/null
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null
grep -q "^/wp/v1/revoke {\"token\":\"$TOKEN\"}" "$LOG" && pass "uninstall ended the wp-config.php credential" || fail "constant revoke: $(cat "$LOG")"
grep -q "define( 'UPTIMEWIRE_TOKEN'" wp-config.php && pass "the line stays in wp-config.php (documented)" || fail "wp-config.php changed"
cfg delete UPTIMEWIRE_TOKEN

cp -r /src/build/uptimewire wp-content/plugins/uptimewire && chown -R www-data:www-data wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null
reset_plugin
# A connection of version 0.4.0: no address fingerprint, so nothing proves it is this site's.
$WP eval 'UptimeWire\Connection::save( "'"$TOKEN"'", array( "id" => "x", "site_url" => "https://www.customer-site.com", "local_url" => "https://www.customer-site.com/", "connected_by" => 1 ) );' >/dev/null
fake '{"/wp/v1/revoke":[[200,{}]]}'
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null
same "uninstall never ends an unproven older connection" "$(calls /wp/v1/revoke)" 0
same "its data removed all the same" "$(rows)" 0

cp -r /src/build/uptimewire wp-content/plugins/uptimewire && chown -R www-data:www-data wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null
no_token_in "debug.log" wp-content/debug.log
$WP option delete uwtest_fake_api >/dev/null
rm -f wp-content/mu-plugins/uw-fake-api.php
rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

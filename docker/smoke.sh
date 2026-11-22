#!/usr/bin/env bash
# Runs inside a stand container after install-wp.sh: checks the plugin end to end
# through real HTTP requests to wp-admin, then uninstalls it.
#   smoke.sh <port>
# Prints PASS/FAIL lines and exits non-zero on the first failure.
set -uo pipefail
PORT="$1"
BASE="http://localhost:$PORT"
WP="wp --allow-root --path=/var/www/html"
CURL="curl -s --connect-to localhost:$PORT:127.0.0.1:80"
TMP=$(mktemp -d)
FAILS=0

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
check() { if grep -q -- "$2" "$3"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
absent() { if grep -q -- "$2" "$3"; then fail "$1 (found: $2)"; else pass "$1"; fi; }

login() { # user password jar
	$CURL -c "$3" -b "$3" -o /dev/null "$BASE/wp-login.php"
	$CURL -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data "testcookie=1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}

# Stand setup: debug log, outgoing request log, an editor, a clean state.
$WP config set WP_DEBUG true --raw >/dev/null
$WP config set WP_DEBUG_LOG true --raw >/dev/null
$WP config set WP_DEBUG_DISPLAY false --raw >/dev/null
mkdir -p /var/www/html/wp-content/mu-plugins
cp /src/docker/http-log.php /var/www/html/wp-content/mu-plugins/uw-http-log.php
rm -f /var/www/html/wp-content/debug.log /var/www/html/wp-content/http-log.txt
$WP user get editor >/dev/null 2>&1 || $WP user create editor editor@example.com --role=editor --user_pass=stand-editor-pass >/dev/null
$WP option delete uptimewire_site_url >/dev/null 2>&1
$WP plugin is-active uptimewire || $WP plugin activate uptimewire >/dev/null
chown -R www-data:www-data /var/www/html/wp-content

login admin stand-admin-pass "$TMP/admin"
login editor stand-editor-pass "$TMP/editor"

# 1. Overview as administrator: welcome screen, local-site warning, disabled buttons.
code=$($CURL -b "$TMP/admin" -o "$TMP/overview.html" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=uptimewire")
[ "$code" = 200 ] && pass "overview 200" || fail "overview http $code"
check "headline" "Keep Your WordPress Website Online" "$TMP/overview.html"
check "local warning" "This looks like a local site" "$TMP/overview.html"
check "buttons disabled" '<fieldset disabled aria-describedby="uptimewire-connect-unavailable">' "$TMP/overview.html"
check "unavailable note" "Connection is not available in this build." "$TMP/overview.html"
check "site address" "http://localhost:$PORT/" "$TMP/overview.html"
check "nonce in form" 'name="_wpnonce"' "$TMP/overview.html"
check "help tab" 'tab-panel-uptimewire-overview' "$TMP/overview.html"
check "stylesheet" 'plugins/uptimewire/assets/admin.css' "$TMP/overview.html"
check "menu icon" 'data:image/svg+xml;base64' "$TMP/overview.html"
check "footnote" "Monitoring runs on UptimeWire servers, outside WordPress." "$TMP/overview.html"
absent "no staging checkbox on a local site" 'uptimewire_understand' "$TMP/overview.html"

# 2. Settings: Not connected.
code=$($CURL -b "$TMP/admin" -o "$TMP/settings.html" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=uptimewire-settings")
[ "$code" = 200 ] && pass "settings 200" || fail "settings http $code"
check "not connected" "Not connected" "$TMP/settings.html"
check "settings help tab" 'tab-panel-uptimewire-privacy' "$TMP/settings.html"

# 3. Other admin screens: no notice, no stylesheet, only the menu item.
$CURL -b "$TMP/admin" -o "$TMP/dashboard.html" "$BASE/wp-admin/index.php"
absent "no stylesheet on dashboard" 'uptimewire-admin-css' "$TMP/dashboard.html"
absent "no notice on dashboard" 'uptimewire-warning\|UptimeWire</strong>' "$TMP/dashboard.html"
check "menu item on dashboard" 'page=uptimewire' "$TMP/dashboard.html"

# 4. Editor: no menu, no access.
$CURL -b "$TMP/editor" -o "$TMP/editor-dash.html" "$BASE/wp-admin/index.php"
absent "editor has no menu" 'page=uptimewire' "$TMP/editor-dash.html"
code=$($CURL -b "$TMP/editor" -o "$TMP/editor-page.html" -w '%{http_code}' "$BASE/wp-admin/admin.php?page=uptimewire")
[ "$code" = 403 ] && pass "editor gets 403 on overview" || fail "editor overview http $code"
nonce=$(grep -o 'name="_wpnonce" value="[^"]*"' "$TMP/overview.html" | head -1 | sed 's/.*value="//;s/"//')
code=$($CURL -b "$TMP/editor" -o /dev/null -w '%{http_code}' --data "action=uptimewire_save_site_url&_wpnonce=$nonce&uptimewire_site_url=https://evil.example.com/" "$BASE/wp-admin/admin-post.php")
[ "$code" = 403 ] && pass "editor cannot save address" || fail "editor save http $code"

# 5. Address form as administrator.
post() { $CURL -b "$TMP/admin" -o /dev/null -w '%{http_code} %{redirect_url}' "$@" "$BASE/wp-admin/admin-post.php"; }
r=$(post --data "action=uptimewire_save_site_url&uptimewire_site_url=https://www.customer-site.com/")
case "$r" in 403*) pass "no nonce rejected" ;; *) fail "no nonce: $r" ;; esac
[ -z "$($WP option get uptimewire_site_url 2>/dev/null)" ] && pass "nothing saved without nonce" || fail "saved without nonce"

r=$(post --data "action=uptimewire_save_site_url&_wpnonce=$nonce" --data-urlencode "uptimewire_site_url=javascript:alert(1)")
case "$r" in *uptimewire_notice=site_url_invalid*) pass "invalid address rejected" ;; *) fail "invalid: $r" ;; esac

r=$(post --data "action=uptimewire_save_site_url&_wpnonce=$nonce" --data-urlencode "uptimewire_site_url=https://www.customer-site.com/")
case "$r" in *uptimewire_notice=site_url_saved*) pass "address saved" ;; *) fail "save: $r" ;; esac
[ "$($WP option get uptimewire_site_url)" = "https://www.customer-site.com/" ] && pass "option value" || fail "option value"
autoload=$($WP eval 'global $wpdb; echo $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = \"uptimewire_site_url\"" );')
case "$autoload" in no|off) pass "option not autoloaded ($autoload)" ;; *) fail "autoload=$autoload" ;; esac

$CURL -b "$TMP/admin" -o "$TMP/overview2.html" "$BASE/wp-admin/admin.php?page=uptimewire&uptimewire_notice=site_url_saved"
check "saved notice" "Website address saved." "$TMP/overview2.html"
absent "public https site: no warning" 'uptimewire-warning' "$TMP/overview2.html"
check "reset button" 'name="uptimewire_reset"' "$TMP/overview2.html"

$WP config set WP_ENVIRONMENT_TYPE staging >/dev/null
sleep 3 # PHP 8.3 images cache compiled files (opcache) and recheck them every 2 seconds.
$CURL -b "$TMP/admin" -o "$TMP/overview3.html" "$BASE/wp-admin/admin.php?page=uptimewire"
check "staging warning" "This looks like a staging or development site" "$TMP/overview3.html"
check "I understand checkbox" 'uptimewire_understand' "$TMP/overview3.html"
$WP config delete WP_ENVIRONMENT_TYPE >/dev/null
sleep 3

$WP option update uptimewire_site_url "http://www.customer-site.com/" >/dev/null
$CURL -b "$TMP/admin" -o "$TMP/overview4.html" "$BASE/wp-admin/admin.php?page=uptimewire"
check "http notice" "doesn&#039;t use HTTPS" "$TMP/overview4.html"

r=$(post --data "action=uptimewire_save_site_url&_wpnonce=$nonce&uptimewire_reset=1")
case "$r" in *uptimewire_notice=site_url_reset*) pass "reset" ;; *) fail "reset: $r" ;; esac
[ -z "$($WP option get uptimewire_site_url 2>/dev/null)" ] && pass "option removed by reset" || fail "option left after reset"

# 6. Capability filter.
out=$($WP eval 'add_filter( "uptimewire_capability", function () { return "edit_posts"; } ); echo UptimeWire\Plugin::capability();')
[ "$out" = edit_posts ] && pass "capability filter" || fail "capability filter: $out"
out=$($WP eval 'add_filter( "uptimewire_capability", "__return_empty_string" ); echo UptimeWire\Plugin::capability();')
[ "$out" = manage_options ] && pass "empty capability falls back" || fail "empty capability: $out"

# 7. Nothing requested by the plugin; no PHP notices.
if grep -q '^UPTIMEWIRE' /var/www/html/wp-content/http-log.txt 2>/dev/null; then
	fail "plugin made HTTP requests: $(grep '^UPTIMEWIRE' /var/www/html/wp-content/http-log.txt | head -3)"
else
	pass "no HTTP requests from the plugin ($(cat /var/www/html/wp-content/http-log.txt 2>/dev/null | wc -l) core requests logged)"
fi
if [ -s /var/www/html/wp-content/debug.log ]; then
	fail "debug.log not empty:"; head -20 /var/www/html/wp-content/debug.log
else
	pass "debug.log empty"
fi

# 8. Uninstall removes every uptimewire_ option and transient, nothing else.
$WP option update uptimewire_site_url "https://www.customer-site.com/" --autoload=no >/dev/null
$WP option update uptimewire_connection '{"t":1}' --autoload=no >/dev/null
$WP transient set uptimewire_status_fresh 1 300 >/dev/null
$WP option update uptimewirex_keep 1 >/dev/null
$WP option update my_uptimewire_keep 1 >/dev/null
$WP plugin deactivate uptimewire >/dev/null
$WP plugin uninstall uptimewire >/dev/null && pass "uninstall ran" || fail "uninstall failed"
left=$($WP eval 'global $wpdb; echo implode( " ", $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"%uptimewire%\" ORDER BY option_name" ) ), " ";')
[ "$left" = "my_uptimewire_keep uptimewirex_keep " ] && pass "only foreign options left: $left" || fail "left after uninstall: $left"
[ ! -d /var/www/html/wp-content/plugins/uptimewire ] && pass "plugin files deleted" || fail "plugin files left"
$WP option delete uptimewirex_keep my_uptimewire_keep >/dev/null

# Put the plugin back for the browser check.
cp -r /src/build/uptimewire /var/www/html/wp-content/plugins/uptimewire
chown -R www-data:www-data /var/www/html/wp-content/plugins/uptimewire
$WP plugin activate uptimewire >/dev/null

rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

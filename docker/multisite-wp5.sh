#!/usr/bin/env bash
# WP-5 on a multisite network, inside a stand container after install-wp.sh: each site disconnects
# only its own connection, a cloned subsite never ends the original's, the main site's wp-config.php
# credential stays its own, and uninstall cleans every site. Turns the install into a network.
#   multisite-wp5.sh <port>
# Ends by uninstalling the plugin (its files are deleted).
set -uo pipefail
PORT="$1"
BASE="http://localhost:$PORT"
WP="php -d display_errors=0 -d error_reporting=0 /usr/local/bin/wp --allow-root --path=/var/www/html"
TWO="--url=localhost:$PORT/two/"
THREE="--url=localhost:$PORT/three/"
FOUR="--url=localhost:$PORT/four/"
CURL="curl -s --connect-to localhost:$PORT:127.0.0.1:80"
TMP=$(mktemp -d)
FAILS=0
LOG=/var/www/html/wp-content/fake-api-log.txt
TOKEN_MAIN=uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
TOKEN_TWO=uwp_2222222222222222222222222222222222222222222222222222222222222222
TOKEN_THREE=uwp_3333333333333333333333333333333333333333333333333333333333333333

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
check() { if grep -q -- "$2" "$3"; then pass "$1"; else fail "$1 (missing: $2)"; fi; }
absent() { if grep -q -- "$2" "$3"; then fail "$1 (found: $2)"; else pass "$1"; fi; }
same() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$3', got '$2')"; fi; }
calls() { grep -c "^$1 " "$LOG" 2>/dev/null || true; }
login() { # user password jar
	$CURL -c "$3" -b "$3" -o /dev/null "$BASE/wp-login.php"
	$CURL -c "$3" -b "$3" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$2" \
		--data "testcookie=1&redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}
get() { $CURL -b "$1" -o "$2" -w '%{http_code}' "$3"; }
form_nonce() { perl -0ne 'print $1 if /value="'"$1"'">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([0-9a-f]+)"/' "$2"; }
post() { $CURL -b "$1" -o /dev/null -w '%{http_code} %{redirect_url}' --data "$3" "$2/wp-admin/admin-post.php"; }
fake() { # JSON object of answers per path, for every site of the network
	for s in "" "$TWO" "$THREE" "$FOUR"; do $WP option update uwtest_fake_api "$1" --format=json $s >/dev/null; done
	: > "$LOG"; chmod 666 "$LOG"
}
connect() { # token site-url account url-arg
	$WP eval 'UptimeWire\Connection::save( "'"$1"'", UptimeWire\Connection::identity() + array( "id" => "0196a3b2-'"${3: -4}"'-7e3f-8a9b-0c1d2e3f4a5b", "connected_at" => "2026-11-23T08:37:39+00:00", "site_url" => "'"$2"'", "local_url" => "'"$2"'/", "connected_by" => 1, "account_label" => "'"$3"'" ) );' $4 >/dev/null
	$WP option update uptimewire_site_url "$2/" --autoload=no $4 >/dev/null 2>&1
}
token_of() { $WP eval 'echo UptimeWire\Connection::token();' $1; }
plugin_rows() { $WP eval 'global $wpdb; $n = 0; foreach ( get_sites( array( "fields" => "ids" ) ) as $id ) { $n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->get_blog_prefix( $id ) . "options WHERE option_name LIKE \"%uptimewire%\"" ); } echo $n + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE \"%uptimewire%\"" );'; }

cd /var/www/html
$WP core is-installed --network 2>/dev/null || $WP core multisite-convert --title="UW network" >/dev/null
sites=$($WP site list --field=path)
for slug in two three four; do grep -q "^/$slug/\$" <<< "$sites" || $WP site create --slug=$slug >/dev/null; done
$WP plugin activate uptimewire --network >/dev/null 2>&1
# Subdirectory sites need the rewrite rules of WordPress multisite (stand only), as in multisite-connect.sh.
cat > .htaccess <<'HT'
RewriteEngine On
RewriteBase /
RewriteRule ^index.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*.php)$ $2 [L]
RewriteRule . index.php [L]
HT
a2enmod rewrite >/dev/null 2>&1 && apache2ctl graceful >/dev/null 2>&1; sleep 1
mkdir -p wp-content/mu-plugins
cp /src/docker/fake-api.php wp-content/mu-plugins/uw-fake-api.php
chown -R www-data:www-data wp-content
$WP user get twoadmin >/dev/null 2>&1 || $WP user create twoadmin twoadmin@example.com --role=administrator --user_pass=stand-two-pass $TWO >/dev/null
login admin stand-admin-pass "$TMP/super"
login twoadmin stand-two-pass "$TMP/two"

echo "--- every site its own connection; the main site's wp-config.php credential is its own"
connect "$TOKEN_TWO" https://two.customer-site.com ACC-00000222 "$TWO"
connect "$TOKEN_THREE" https://three.customer-site.com ACC-00000333 "$THREE"
# The main site is monitored at its WordPress address, so UptimeWire's answer proves the constant is this site's.
$WP option update uptimewire_site_url "$BASE/" --autoload=no >/dev/null
$WP config set UPTIMEWIRE_TOKEN "$TOKEN_MAIN" >/dev/null
sleep 3
fake '{"/wp/v1/connection":[[200,{"data":{"id":"0196a3b2-0000-7e3f-8a9b-0c1d2e3f4a5b","connected_at":"2026-11-23T08:37:39+00:00","account_label":"ACC-00000111","site":{"url":"'"$BASE"'"}}}]],"/wp/v1/revoke":[[200,{}]]}'
same "main site connected with the constant" "$($WP eval 'UptimeWire\Connection::check_health( true ); echo UptimeWire\State::current();')" connection_established

echo "--- the network-wide wp-config.php credential: only a network administrator disconnects it"
$WP user get mainadmin >/dev/null 2>&1 || $WP user create mainadmin mainadmin@example.com --role=administrator --user_pass=stand-main-pass >/dev/null
login mainadmin stand-main-pass "$TMP/main"
get "$TMP/main" "$TMP/main-settings.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings" >/dev/null
absent "main-site administrator: no Disconnect" 'uptimewire_confirm=disconnect' "$TMP/main-settings.html"
get "$TMP/super" "$TMP/super-confirm.html" "$BASE/wp-admin/admin.php?page=uptimewire-settings&uptimewire_confirm=disconnect" >/dev/null
check "network administrator: Disconnect offered" 'Disconnect UptimeWire?' "$TMP/super-confirm.html"
# The page gives this user no Disconnect nonce, so the handler's own rule is asked directly.
same "main-site administrator may not end the constant" "$($WP eval 'echo UptimeWire\Connection::may_touch_constant() ? "yes" : "no";' --user=mainadmin)" no
same "network administrator may" "$($WP eval 'echo UptimeWire\Connection::may_touch_constant() ? "yes" : "no";' --user=admin)" yes
same "the constant still works" "$($WP eval 'echo UptimeWire\State::current();')" connection_established
same "nothing ended" "$(calls /wp/v1/revoke)" 0
same "site two keeps its own credential" "$(token_of "$TWO")" "$TOKEN_TWO"
same "site three keeps its own credential" "$(token_of "$THREE")" "$TOKEN_THREE"

echo "--- a cloned subsite (site two's rows copied to site four)"
$WP option update uptimewire_connection "$($WP option get uptimewire_connection --format=json $TWO)" --format=json $FOUR >/dev/null
$WP option update uptimewire_site_url "https://two.customer-site.com/" --autoload=no $FOUR >/dev/null 2>&1
same "site four is a copy" "$($WP eval 'echo UptimeWire\State::current();' $FOUR)" site_copy
: > "$LOG"
get "$TMP/super" "$TMP/four.html" "$BASE/four/wp-admin/admin.php?page=uptimewire" >/dev/null
check "copy screen on site four" 'This WordPress Site Is a Copy' "$TMP/four.html"
absent "site four shows no account of site two" 'ACC-00000222' "$TMP/four.html"
same "site four sends nothing" "$(wc -l < "$LOG")" 0
KNONCE=$(form_nonce uptimewire_remove_local "$TMP/four.html")
post "$TMP/super" "$BASE/four" "action=uptimewire_remove_local&_wpnonce=$KNONCE" >/dev/null
same "copy removed on site four" "$($WP eval 'echo UptimeWire\State::current();' $FOUR)" not_connected
same "site two's connection is not ended" "$(calls /wp/v1/revoke)" 0
same "site two still connected" "$($WP eval 'echo UptimeWire\State::current();' $TWO)" connection_established

echo "--- an administrator of site two only"
get "$TMP/two" "$TMP/two-confirm.html" "$BASE/two/wp-admin/admin.php?page=uptimewire-settings&uptimewire_confirm=disconnect" >/dev/null
check "own confirmation" 'Disconnect UptimeWire?' "$TMP/two-confirm.html"
NONCE=$(form_nonce uptimewire_disconnect "$TMP/two-confirm.html")
same "site three's Settings: refused" "$(get "$TMP/two" "$TMP/two-on-three.html" "$BASE/three/wp-admin/admin.php?page=uptimewire-settings")" 403
absent "nothing of site three shown" 'ACC-00000333' "$TMP/two-on-three.html"
same "site three's disconnect: refused" "$(post "$TMP/two" "$BASE/three" "action=uptimewire_disconnect&_wpnonce=$NONCE" | cut -d' ' -f1)" 403
same "main site's disconnect: refused" "$(post "$TMP/two" "$BASE" "action=uptimewire_disconnect&_wpnonce=$NONCE" | cut -d' ' -f1)" 403
same "site three's remove-local: refused" "$(post "$TMP/two" "$BASE/three" "action=uptimewire_remove_local&_wpnonce=$NONCE" | cut -d' ' -f1)" 403
same "nothing ended" "$(calls /wp/v1/revoke)" 0
same "site three still connected" "$(token_of "$THREE")" "$TOKEN_THREE"
result=$(post "$TMP/two" "$BASE/two" "action=uptimewire_disconnect&_wpnonce=$NONCE")
case "$result" in *"uptimewire_notice=disconnected"*) pass "site two disconnected itself" ;; *) fail "site two disconnect: $result" ;; esac
grep -q "^/wp/v1/revoke {\"token\":\"$TOKEN_TWO\"}" "$LOG" && pass "only site two's credential was ended" || fail "revoke: $(cat "$LOG")"
same "one revoke" "$(calls /wp/v1/revoke)" 1
same "site three unaffected" "$(token_of "$THREE")" "$TOKEN_THREE"
same "main site unaffected" "$($WP eval 'echo UptimeWire\State::current();')" connection_established

echo "--- Site Health and Network Admin"
get "$TMP/super" "$TMP/info3.html" "$BASE/three/wp-admin/site-health.php?tab=debug" >/dev/null
check "site three's Info: its account" 'ACC-00000333' "$TMP/info3.html"
absent "site three's Info: not site two's website" 'two.customer-site.com' "$TMP/info3.html"
absent "site three's Info: not the main site's account" 'ACC-00000111' "$TMP/info3.html"
for page in index.php plugins.php sites.php; do
	get "$TMP/super" "$TMP/net.html" "$BASE/wp-admin/network/$page" >/dev/null
	absent "network $page: no plugin assets" 'uptimewire/assets/' "$TMP/net.html"
done

echo "--- uninstall over the network"
connect "$TOKEN_TWO" https://two.customer-site.com ACC-00000222 "$TWO"
$WP option update uptimewire_connection "$($WP option get uptimewire_connection --format=json $TWO)" --format=json $FOUR >/dev/null
$WP option update other_plugin_keep 1 $THREE >/dev/null
$WP site option update uptimewire_network_probe 1 >/dev/null
fake '{"/wp/v1/revoke":[[200,{}]]}'
$WP plugin deactivate uptimewire --network >/dev/null
$WP plugin uninstall uptimewire >/dev/null && pass "uninstall finished" || fail "uninstall failed"
for t in "$TOKEN_MAIN" "$TOKEN_TWO" "$TOKEN_THREE"; do
	same "ended once: ${t:0:8}…" "$(grep -c "^/wp/v1/revoke {\"token\":\"$t\"}" "$LOG")" 1
done
same "the clone ended nothing more" "$(calls /wp/v1/revoke)" 3
same "no plugin rows on any site or the network" "$(plugin_rows)" 0
same "other plugin's option kept" "$($WP option get other_plugin_keep $THREE)" 1
grep -q "define( 'UPTIMEWIRE_TOKEN'" wp-config.php && pass "wp-config.php untouched" || fail "wp-config.php changed"

$WP config delete UPTIMEWIRE_TOKEN >/dev/null
$WP option delete other_plugin_keep $THREE >/dev/null
rm -f wp-content/mu-plugins/uw-fake-api.php
rm -rf "$TMP"
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

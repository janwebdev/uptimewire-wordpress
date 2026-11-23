#!/usr/bin/env bash
# WP-2 on a multisite network, inside a stand container after install-wp.sh:
# turns the install into a subdirectory network, connects only the site /two/
# against the fake API and checks that nothing reaches the other sites.
#   multisite-connect.sh <port>
# Destructive for the stand: the install stays a network afterwards.
set -uo pipefail
PORT="$1"
BASE="http://localhost:$PORT"
WP="php -d display_errors=0 -d error_reporting=0 /usr/local/bin/wp --allow-root --path=/var/www/html"
TWO="--url=localhost:$PORT/two/"
CURL="curl -s --connect-to localhost:$PORT:127.0.0.1:80"
FAILS=0
AUTH_ID=0196a3b2-1c2d-7e3f-8a9b-0c1d2e3f4a5b
DEVICE='[200,{"device_code":"uwd_fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210","user_code":"WDJB-MJHT","verification_uri":"https://app.uptimewire.com/connect","verification_uri_complete":"https://app.uptimewire.com/connect?code=WDJB-MJHT","expires_in":600,"interval":5,"authorization_id":"'$AUTH_ID'"}]'
GRANTED='[200,{"access_token":"uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef","token_type":"Bearer","connection":{"id":"0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b","connected_at":"2026-11-23T08:37:39+00:00","site":{"url":"https://two.customer-site.com"}}}]'

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }

cd /var/www/html
$WP core is-installed --network 2>/dev/null || $WP core multisite-convert --title="UW network" >/dev/null
# The list is read whole: "grep -q" on a pipe would end it early and fail under pipefail.
sites=$($WP site list --field=path)
grep -q '^/two/$' <<< "$sites" || $WP site create --slug=two >/dev/null
grep -q '^/three/$' <<< "$sites" || $WP site create --slug=three >/dev/null
mkdir -p wp-content/mu-plugins
cp /src/docker/fake-api.php wp-content/mu-plugins/uw-fake-api.php
rm -f wp-content/mu-plugins/uw-live-forward.php
[ -d wp-content/plugins/uptimewire ] || cp -r /src/build/uptimewire wp-content/plugins/uptimewire
# Subdirectory sites need the rewrite rules of WordPress multisite (stand only).
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
chown -R www-data:www-data wp-content
$WP plugin activate uptimewire --network >/dev/null

$WP option update uptimewire_site_url "https://two.customer-site.com/" --autoload=no $TWO >/dev/null
$WP option update uwtest_fake_api '{"/wp/v1/device":['"$DEVICE"'],"/wp/v1/token":['"$GRANTED"']}' --format=json $TWO >/dev/null

out=$($WP eval 'echo wp_json_encode( UptimeWire\Authorization::start( 1, false ) );' $TWO)
echo "$out" | grep -q '"ok":true' && pass "site two starts connecting" || fail "start on site two: $out"
grep -q '"multisite":"1"' wp-content/fake-api-log.txt && pass "multisite=1 sent" || fail "multisite flag: $(cat wp-content/fake-api-log.txt)"

PROOF=$($WP eval 'echo UptimeWire\Authorization::pending()["proof"];' $TWO)
two=$($CURL "$BASE/two/?uptimewire_proof=$AUTH_ID")
one=$($CURL "$BASE/?uptimewire_proof=$AUTH_ID")
three=$($CURL "$BASE/three/?uptimewire_proof=$AUTH_ID")
[ "$two" = "{\"proof\":\"$PROOF\"}" ] && pass "proof on site two" || fail "proof on two: $(echo "$two" | head -c 120)"
echo "$one$three" | grep -q "$PROOF" && fail "proof leaked to another site" || pass "main site and site three answer with their normal page"

$WP eval '$p = get_transient( "uptimewire_pending" ); $p["next_poll_at"] = time() - 1; set_transient( "uptimewire_pending", $p, 900 ); echo wp_json_encode( UptimeWire\Authorization::poll() );' $TWO >/dev/null
state=$($WP eval 'echo UptimeWire\State::current();' $TWO)
[ "$state" = connection_established ] && pass "site two connected" || fail "site two state: $state"
for s in "" "--url=localhost:$PORT/three/"; do
	st=$($WP eval 'echo UptimeWire\State::current();' $s)
	[ "$st" = not_connected ] && pass "other site ${s:-main} not connected" || fail "other site ${s:-main}: $st"
done
tables=$($WP eval 'global $wpdb; echo implode( " ", $wpdb->get_col( "SHOW TABLES LIKE \"%options\"" ) );')
for t in $tables; do
	n=$($WP eval "global \$wpdb; echo \$wpdb->get_var( \"SELECT COUNT(*) FROM $t WHERE option_name = 'uptimewire_connection'\" );")
	case "$t" in *_2_options) [ "$n" = 1 ] && pass "$t holds the connection" || fail "$t: $n" ;; *) [ "$n" = 0 ] && pass "$t has no connection" || fail "$t: $n" ;; esac
done
net=$($WP eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE \"%uptimewire%\"" );')
[ "$net" = 0 ] && pass "nothing stored network-wide" || fail "network rows: $net"

$WP option delete uwtest_fake_api $TWO >/dev/null
rm -f wp-content/mu-plugins/uw-fake-api.php
echo "FAILS=$FAILS"
[ "$FAILS" = 0 ]

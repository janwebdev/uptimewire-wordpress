#!/usr/bin/env bash
# Runs inside a stand container: turns the install into a multisite network with
# three sites, stores plugin options on two of them, uninstalls the plugin and
# prints which sites were visited and what is left.
#   multisite-uninstall.sh <port>
# Destructive for the stand: the install stays a network afterwards.
set -euo pipefail
PORT="$1"
cd /var/www/html
WP="wp --allow-root"

$WP core is-installed --network 2>/dev/null || $WP core multisite-convert --title="UW network" >/dev/null
# The list is read whole: "grep -q" on a pipe would end it early and fail under pipefail.
sites=$($WP site list --field=path)
grep -q '^/two/$' <<< "$sites" || $WP site create --slug=two >/dev/null
grep -q '^/three/$' <<< "$sites" || $WP site create --slug=three >/dev/null
$WP site list --fields=blog_id,url

cat > wp-content/mu-plugins/uw-switch-log.php <<'PHP'
<?php
add_action( 'switch_blog', function ( $new_blog ) { file_put_contents( WP_CONTENT_DIR . '/switch-log.txt', "switch to $new_blog\n", FILE_APPEND ); } );
PHP

[ -d wp-content/plugins/uptimewire ] || cp -r /src/build/uptimewire wp-content/plugins/uptimewire
$WP plugin activate uptimewire --network >/dev/null
$WP option update uptimewire_site_url "https://www.customer-site.com/" --autoload=no >/dev/null
$WP option update uptimewire_site_url "https://two.customer-site.com/" --autoload=no --url="localhost:$PORT/two/" >/dev/null
$WP transient set uptimewire_status_fresh 1 300 --url="localhost:$PORT/two/" >/dev/null
$WP option update other_plugin_keep 1 --url="localhost:$PORT/three/" >/dev/null
$WP site option update uptimewire_network_probe 1 >/dev/null
$WP transient set uptimewire_net_probe 1 300 --network >/dev/null
$WP option update uptimewirex_keep 1 >/dev/null

rm -f wp-content/switch-log.txt
$WP plugin deactivate uptimewire --network >/dev/null
$WP plugin uninstall uptimewire

echo "--- sites visited during uninstall:"
sort wp-content/switch-log.txt 2>/dev/null | uniq -c || echo "(none)"
echo "--- options with 'uptimewire' left per site:"
$WP eval '
global $wpdb;
foreach ( get_sites( array( "fields" => "ids" ) ) as $id ) {
	$t = $wpdb->get_blog_prefix( $id ) . "options";
	echo "site $id: ", implode( " ", $wpdb->get_col( "SELECT option_name FROM $t WHERE option_name LIKE \"%uptimewire%\"" ) ), PHP_EOL;
}
echo "network: ", implode( " ", $wpdb->get_col( "SELECT meta_key FROM $wpdb->sitemeta WHERE meta_key LIKE \"%uptimewire%\"" ) ), PHP_EOL;
'
echo "--- foreign option on site 3: $($WP option get other_plugin_keep --url="localhost:$PORT/three/")"
$WP option delete uptimewirex_keep >/dev/null
rm -f wp-content/mu-plugins/uw-switch-log.php wp-content/switch-log.txt

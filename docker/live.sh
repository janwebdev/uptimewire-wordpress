#!/usr/bin/env bash
# Live check against a local copy of UptimeWire (container uw-wp2-web, 11.77.42.90),
# inside the stand container uw-wp2-php83. The site's "Website to monitor" is
# http://11.77.42.83/, the stand's address in the public-like test network, so
# the copy can fetch the proof from it.
#   live.sh setup | start | pending | poll | reset | log
set -uo pipefail
WP="php -d display_errors=0 -d error_reporting=0 /usr/local/bin/wp --allow-root --path=/var/www/html"
CONTENT=/var/www/html/wp-content

case "${1:-}" in
setup)
	mkdir -p "$CONTENT/mu-plugins"
	cp /src/docker/live-forward.php "$CONTENT/mu-plugins/uw-live-forward.php"
	rm -f "$CONTENT/mu-plugins/uw-fake-api.php" "$CONTENT/mu-plugins/uw-block-bot.php" "$CONTENT/live-log.txt"
	touch "$CONTENT/live-log.txt"
	$WP option update uptimewire_site_url "http://11.77.42.83/" --autoload=no
	chown -R www-data:www-data "$CONTENT"
	;;
start)
	$WP eval 'echo wp_json_encode( UptimeWire\Authorization::start( 1, false ) ), "\n";'
	;;
pending)
	$WP eval '$p = UptimeWire\Authorization::pending(); echo $p ? $p["user_code"] . " " . $p["verification_uri_complete"] . " status=" . $p["status"] . " interval=" . $p["interval"] : "none"; echo "\n";'
	;;
poll)
	$WP eval '$p = get_transient( "uptimewire_pending" ); if ( $p ) { $p["next_poll_at"] = time() - 1; set_transient( "uptimewire_pending", $p, 900 ); } echo wp_json_encode( UptimeWire\Authorization::poll() ), "\n";'
	;;
reset)
	$WP eval 'foreach ( array( "uptimewire_connection", "uptimewire_outcome" ) as $o ) { delete_option( $o ); } UptimeWire\Authorization::cancel(); delete_transient( "uptimewire_cancelled" ); echo "reset\n";'
	;;
log)
	cat "$CONTENT/live-log.txt" 2>/dev/null
	;;
*)
	echo "usage: live.sh setup|start|pending|poll|reset|log" >&2
	exit 2
	;;
esac

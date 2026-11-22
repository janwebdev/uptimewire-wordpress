#!/usr/bin/env bash
# Runs inside a stand container: (re)installs WordPress of a given version and
# copies the built plugin from /src/build/uptimewire.
#   install-wp.sh <wp-version> <db-name> <port>
# Test-only credentials: admin / stand-admin-pass.
set -euo pipefail
WP_VERSION="$1"
DB_NAME="$2"
PORT="$3"
WP="wp --allow-root --path=/var/www/html"

# WP-CLI needs ZipArchive for core downloads; the tarball works on every image.
curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" | tar -xz --strip-components=1 -C /var/www/html
if [ ! -f /var/www/html/wp-config.php ]; then
	$WP config create --dbname="$DB_NAME" --dbuser=wp --dbpass=stand-wp --dbhost=uw-wp1-db --skip-check
fi
if ! $WP core is-installed 2>/dev/null; then
	$WP core install --url="http://localhost:$PORT" --title="UptimeWire stand" \
		--admin_user=admin --admin_password=stand-admin-pass --admin_email=admin@example.com --skip-email
fi
$WP core update-db
mkdir -p /var/www/html/wp-content/plugins
rm -rf /var/www/html/wp-content/plugins/uptimewire
cp -r /src/build/uptimewire /var/www/html/wp-content/plugins/uptimewire
chown -R www-data:www-data /var/www/html
$WP core version

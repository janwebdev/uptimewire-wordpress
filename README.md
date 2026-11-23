# UptimeWire for WordPress

The WordPress plugin of [UptimeWire](https://uptimewire.com), a website monitoring service that checks uptime, SSL certificates, domain expiry and DNS from outside your server.

> **Status: in development. Not released.** The plugin connects a site to an UptimeWire account (RFC 8628 device authorization) and shows its monitoring status on the Overview screen (0.3.0). Do not use it on a live site yet. The plugin is not in the WordPress.org directory.

## What it does

- Adds an **UptimeWire** menu (Overview, Settings), visible to administrators only (`manage_options`, filterable with `uptimewire_capability`).
- Shows a welcome screen that warns about local, staging or development sites and sites without HTTPS, and lets the administrator correct the address to monitor.
- Connects the site to UptimeWire by code: the administrator approves in a new tab, UptimeWire confirms the site through `/?uptimewire_proof=…`, the plugin collects a read-only credential for this one site. The device code never leaves the server; the credential is never shown (optionally kept in `wp-config.php` as `UPTIMEWIRE_TOKEN`).
- Shows the monitoring status of the connected website on Overview: the main status, SSL, domain and DNS cards, uptime for 24 hours, 7 and 30 days, recent events and alert readiness. The status is read from `GET /wp/v1/status`, kept in the option `uptimewire_status` and renewed by the screen's script while it is open (every 1–5 minutes) or by the Refresh button; rendering never waits for UptimeWire.
- Removes everything it stored (`uptimewire_*` options and transients) when deleted, on single sites and on multisite networks.

## Requirements

WordPress 6.5 or later, PHP 7.4 or later. Tested on WordPress 6.5 and 7.1 with PHP 7.4, 8.3 and 8.5.

## Development

The plugin itself has no Composer dependencies. Composer is only used for the development tools (PHPUnit, PHP_CodeSniffer with the WordPress standards).

```sh
composer install
vendor/bin/phpunit           # unit tests
vendor/bin/phpunit -c phpunit-integration.xml.dist   # integration tests, inside a stand container (see the file)
vendor/bin/phpcs             # WordPress coding standards and PHP 7.4 compatibility
bin/build.sh                 # the installable zip, without development files (.distignore)
```

`docker/` holds a throwaway test stand (WordPress with PHP 7.4, 8.3 and 8.5, MariaDB) and the scripted checks: `docker/smoke.sh` (foundation), `docker/smoke-wp2.sh` (connecting, against a fake API), `docker/smoke-wp3.sh` (the dashboard, against a fake API), `docker/live.sh` (against a local copy of UptimeWire), `docker/multisite-uninstall.sh`, `docker/multisite-connect.sh`. The passwords in it are for that local stand only.

## External services

The plugin connects to UptimeWire, an external service, only after an administrator starts the connection. What is sent and when is listed in `readme.txt` under "External services". Terms: https://uptimewire.com/terms/ · Privacy: https://uptimewire.com/privacy/

## License

GPLv2 or later, see https://www.gnu.org/licenses/gpl-2.0.html.

"UptimeWire" and the UptimeWire logo are trademarks of their owner. The GPL license of this code does not grant any rights to use them.

## Security

Please report security problems privately to support@uptimewire.com, not in public issues.

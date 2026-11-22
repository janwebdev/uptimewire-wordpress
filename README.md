# UptimeWire for WordPress

The WordPress plugin of [UptimeWire](https://uptimewire.com), a website monitoring service that checks uptime, SSL certificates, domain expiry and DNS from outside your server.

> **Status: in development. Not released.** This is the plugin's foundation: the admin menu, the welcome screen with checks of the site's environment, the Settings screen and clean uninstall. Connecting a site to UptimeWire is not built yet, so the plugin makes no requests to UptimeWire or anywhere else. Do not use it on a live site yet. The plugin is not in the WordPress.org directory.

## What the foundation does

- Adds an **UptimeWire** menu (Overview, Settings), visible to administrators only (`manage_options`, filterable with `uptimewire_capability`).
- Shows a welcome screen that warns about local, staging or development sites and sites without HTTPS, and lets the administrator correct the address to monitor.
- Sends nothing anywhere. The connect buttons are present but disabled in this build.
- Removes everything it stored (`uptimewire_*` options and transients) when deleted, on single sites and on multisite networks.

## Requirements

WordPress 6.5 or later, PHP 7.4 or later. Tested on WordPress 6.5 and 7.1 with PHP 7.4 and 8.3.

## Development

The plugin itself has no Composer dependencies. Composer is only used for the development tools (PHPUnit, PHP_CodeSniffer with the WordPress standards).

```sh
composer install
vendor/bin/phpunit           # unit tests
vendor/bin/phpcs             # WordPress coding standards and PHP 7.4 compatibility
bin/build.sh                 # the installable zip, without development files (.distignore)
```

`docker/` holds a throwaway test stand (WordPress with PHP 7.4 and 8.3, MariaDB) and the scripted admin checks (`docker/smoke.sh`, `docker/multisite-uninstall.sh`). The passwords in it are for that local stand only.

## External services

When finished, the plugin will connect to UptimeWire, an external service, and only after an administrator starts the connection. What is sent and when will be listed in `readme.txt` under "External services". Terms: https://uptimewire.com/terms/ · Privacy: https://uptimewire.com/privacy/

## License

GPLv2 or later, see https://www.gnu.org/licenses/gpl-2.0.html.

"UptimeWire" and the UptimeWire logo are trademarks of their owner. The GPL license of this code does not grant any rights to use them.

## Security

Please report security problems privately to support@uptimewire.com, not in public issues.

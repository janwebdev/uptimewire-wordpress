=== UptimeWire – Website Uptime & SSL Monitoring ===
Contributors: uptimewire
Tags: uptime, monitoring, ssl, downtime, status
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know when your WordPress website goes down. Uptime, SSL certificate, domain and DNS checks run by UptimeWire from outside your server.

== Description ==

UptimeWire checks your website from its own servers on the internet. Because the checks run outside WordPress, they keep working when your site is offline, and they add no load to your server.

* Get notified when your website goes down.
* Get warned before your SSL certificate or domain expires.
* Free monitoring for one website. No credit card required.

This version (0.1.0) is the plugin foundation: the welcome screen, environment checks and the website address. Connecting to UptimeWire is not available in this version yet.

== External services ==

DRAFT: this section describes the connection planned for a later version. Version 0.1.0 contains no connection code and sends no requests to UptimeWire or anywhere else.

This plugin connects your site to UptimeWire (https://uptimewire.com), an external website monitoring service. Nothing is sent until an administrator clicks "Start Free Monitoring" or "Connect Existing Account".

* When you connect: your site address, WordPress and plugin versions, environment type and whether it is a multisite are sent to app.uptimewire.com, and UptimeWire requests one address on your site to confirm the connection.
* While connected: when an administrator opens the plugin's screens, the dashboard or Site Health, the plugin requests monitoring data for this site from api.uptimewire.com, at most once every 5 minutes.
* When you disconnect or delete the plugin: the plugin asks api.uptimewire.com to close the connection.

No visitor data, user lists or content are sent.

Terms of Service: https://uptimewire.com/terms/
Privacy Policy: https://uptimewire.com/privacy/

== Installation ==

1. Install the plugin from Plugins › Add New, or upload the `uptimewire` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open UptimeWire › Overview in the admin menu.

== Frequently Asked Questions ==

= Can I monitor a local or staging site? =

UptimeWire checks websites from the internet, so local sites (localhost, .local, .test, private network addresses) cannot be monitored. Staging sites can be, if they are public.

= Who can see the plugin screens? =

Administrators (users with the `manage_options` capability). Developers can change the capability with the `uptimewire_capability` filter.

= What happens when I delete the plugin? =

All plugin settings are removed from your database.

== Changelog ==

= 0.1.0 =
* Plugin foundation: admin menu, welcome screen with environment checks, website address, Help tabs, clean uninstall.

== Upgrade Notice ==

= 0.1.0 =
First version.

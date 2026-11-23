=== UptimeWire – Website Uptime & SSL Monitoring ===
Contributors: uptimewire
Tags: uptime, monitoring, ssl, downtime, status
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know when your WordPress website goes down. Uptime, SSL certificate, domain and DNS checks run by UptimeWire from outside your server.

== Description ==

UptimeWire checks your website from its own servers on the internet. Because the checks run outside WordPress, they keep working when your site is offline, and they add no load to your server.

* Get notified when your website goes down.
* Get warned before your SSL certificate or domain expires.
* Free monitoring for one website. No credit card required.

Connect your WordPress site to an UptimeWire account: click "Start Free Monitoring" or "Connect Existing Account", approve the connection in UptimeWire, and the plugin finishes connecting by itself. Then UptimeWire › Overview shows whether your website is online, its SSL certificate, domain and DNS checks, uptime for the last 24 hours, 7 days and 30 days, recent events, and whether alerts can reach you.

The "UptimeWire — Website Status" widget on the WordPress Dashboard answers the same question at a glance: is the website online, its uptime, its SSL certificate and when it was last checked. Tools › Site Health reports the connection, the monitoring, the SSL certificate, the domain expiration and whether a firewall blocks the checks, and its Info tab has an UptimeWire section to copy for support. UptimeWire › Settings sums up the connection, the monitoring, alert readiness and troubleshooting help.

== External services ==

This plugin connects your site to UptimeWire (https://uptimewire.com), an external website monitoring service. Nothing is sent until an administrator clicks "Start Free Monitoring" or "Connect Existing Account".

* When you connect: your site address, WordPress and plugin versions, environment type (production, staging and so on) and whether it is a multisite are sent to api.uptimewire.com. You approve the connection on app.uptimewire.com in a new tab. While you approve, the plugin asks api.uptimewire.com every few seconds whether you have finished, and only while the connection screen is open. UptimeWire then requests one address on your site (`/?uptimewire_proof=…`) to confirm that the request came from this WordPress.
* While connected: while an administrator has UptimeWire › Overview or the WordPress Dashboard with the UptimeWire widget open, the plugin reads this website's monitoring status from api.uptimewire.com at most once every 1 to 5 minutes (as often as the website is checked), and when the administrator clicks Refresh. The answer is kept in the option `uptimewire_status`. Other plugin screens ask api.uptimewire.com whether the connection still works, at most once every 5 minutes. Site Health uses only the kept answer and requests nothing. Nothing is requested in the background when nobody looks.

No visitor data, user lists or content are sent.

Terms of Service: https://uptimewire.com/terms/
Privacy Policy: https://uptimewire.com/privacy/

== Installation ==

1. Install the plugin from Plugins › Add New, or upload the `uptimewire` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open UptimeWire › Overview in the admin menu and click "Start Free Monitoring" or "Connect Existing Account".

== Frequently Asked Questions ==

= Can I monitor a local or staging site? =

UptimeWire checks websites from the internet, so local sites (localhost, .local, .test, private network addresses) cannot be monitored. Staging sites can be, if they are public.

= Who can see the plugin screens? =

Administrators (users with the `manage_options` capability). Developers can change the capability with the `uptimewire_capability` filter.

= UptimeWire says it couldn't confirm my website. What now? =

After you approve, UptimeWire opens one address on your site. Cloudflare, a firewall, a security plugin or a page cache that ignores query strings can block or answer it instead of WordPress. Allow UptimeWire's checks (https://uptimewire.com/resources/guides/allow-uptimewire-checks-through-firewall/) and exclude addresses with `uptimewire_proof` from caching. You don't need to turn the protection off.

= Where is the connection credential stored? Can I keep it out of the database? =

It is stored in the option `uptimewire_connection`, which is not loaded on every page, and it is never shown on any screen. To keep it in `wp-config.php` instead, copy it once with WP-CLI (`wp option pluck uptimewire_connection token`) and add `define( 'UPTIMEWIRE_TOKEN', '…' );`. The plugin then uses the constant and removes the copy from the database. A plugin that logs every outgoing HTTP request would also log the credential, because it is sent in the request header. Remove the constant before connecting the site again. On a multisite network the constant counts for the main site only; the other sites of the network keep their own connections.

= What happens when I delete the plugin? =

All plugin settings are removed from your database.

== Changelog ==

= 0.4.0 =
* "UptimeWire — Website Status" widget on the WordPress Dashboard: status, uptime, SSL certificate, last check, with honest words for old data and short history.
* Site Health tests (connection, monitoring, SSL certificate, domain expiration, blocked checks) and an UptimeWire section in Site Health Info, built from the kept status only.
* Settings: connection, monitoring, alert readiness, plans link and troubleshooting.
* Alert readiness tells configured channels apart from whether monitoring alerts are active now (paused, starting, email not verified).

= 0.3.0 =
* Monitoring dashboard on Overview: website status, SSL certificate, domain and DNS cards, uptime for 24 hours, 7 and 30 days, recent events, alert readiness, the UptimeWire account number.
* The status is kept locally and refreshed while the screen is open, or with the Refresh button; when UptimeWire can't be reached, the last known status is shown as such.

= 0.2.0 =
* Connect the site to an UptimeWire account by code (OAuth 2.0 Device Authorization Grant, RFC 8628): waiting screen, approval in a new tab, site confirmation, address check, clear messages for every outcome.
* Connection state on Overview and Settings.

= 0.1.0 =
* Plugin foundation: admin menu, welcome screen with environment checks, website address, Help tabs, clean uninstall.

== Upgrade Notice ==

= 0.4.0 =
Website status on the WordPress Dashboard and in Site Health.

= 0.3.0 =
Monitoring results inside WordPress.

= 0.2.0 =
Connecting to UptimeWire.

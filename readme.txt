=== UptimeWire – Website Uptime & SSL Monitoring ===
Contributors: uptimewire
Tags: uptime, monitoring, ssl, downtime, status
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know when your WordPress site goes down. UptimeWire checks uptime, SSL, domain and DNS from outside and shows the results in your dashboard.

== Description ==

UptimeWire is an external website monitoring service. It checks your website from its own servers on the internet, so the checks keep working when your site or your server is down, and they add no load to WordPress. This plugin connects your site to an UptimeWire account and shows the results inside WordPress.

**An UptimeWire account is required.** A Free plan is available: one website, no credit card. You can create the account while connecting.

What UptimeWire checks and the plugin shows:

* **Uptime**: whether the website answers, response time, uptime for 24 hours, 7 and 30 days, and downtime events.
* **SSL certificate**: valid, expiring soon or expired.
* **Domain expiration**: when the domain expires, where the registry publishes the date.
* **DNS**: changes to the DNS records of the domain.
* **Email domain**: mail servers (MX), SPF, DMARC and DKIM, with problems told apart from tips. A domain without email is shown as such.
* **Scheduled job**: whether a job you connected in UptimeWire, such as a backup, calls on time, late or reports a failure.

Which checks your plan includes is decided in UptimeWire; the plugin shows "Not in your plan" for the others.

Where you see it in WordPress:

* **UptimeWire › Overview**: the website status, the checks, uptime, recent events and whether alerts can reach you.
* **Dashboard widget** "UptimeWire — Website Status": the answer at a glance.
* **Tools › Site Health**: UptimeWire's results as Site Health tests, and an UptimeWire section in Info to copy for support.
* **UptimeWire › Settings**: the connection, the monitoring, alert readiness, Disconnect and troubleshooting.

What the plugin does not do: it does not run checks itself, does not change monitoring settings, does not watch WP-Cron, and is not a security scanner or a backup tool. Alerts (email and other channels) are sent by UptimeWire and set up there.

== External services ==

This plugin is the client of UptimeWire (https://uptimewire.com), an external website monitoring service run by UptimeWire. It sends nothing until an administrator clicks "Start Free Monitoring" or "Connect Existing Account" (or adds the `UPTIMEWIRE_TOKEN` constant, see the FAQ).

**api.uptimewire.com** (the UptimeWire API)

* When you connect: the site address, the WordPress and plugin versions, the environment type (production, staging and so on) and whether the site is a multisite are sent once. While you approve the connection, the plugin asks every few seconds whether you have finished, only while the connection screen is open.
* While connected: the plugin reads this website's monitoring status while an administrator has the Overview screen or the Dashboard with the widget open, at most once every 1 to 5 minutes (as often as the website is checked), and when you click Refresh. Other plugin screens ask, at most once every 5 minutes, whether the connection still works. Site Health requests nothing. Nothing is requested in the background when nobody looks.
* When you disconnect, or delete the plugin: the plugin asks once to end this site's connection.
* Each request carries the site's connection credential. No visitor data, users, content or other personal data of your site are sent.

**app.uptimewire.com** (the UptimeWire website)

* You approve the connection, sign in or create an account there, in a new browser tab. The plugin links there for details.

**Your website, requested by UptimeWire**

* To confirm the connection, UptimeWire requests one address on your site (`/?uptimewire_proof=…`) once after you approve. Monitoring itself requests your public pages as any visitor would.

Terms of Service: https://uptimewire.com/terms/
Privacy Policy: https://uptimewire.com/privacy/

== Installation ==

1. Install the plugin from Plugins › Add New, or upload the `uptimewire` folder to `/wp-content/plugins/`, and activate it.
2. Open UptimeWire › Overview and click "Start Free Monitoring" (new account) or "Connect Existing Account".
3. Approve the connection in the UptimeWire tab that opens. Check that it shows the same code as WordPress. The plugin finishes connecting by itself.
4. If you created a new account, confirm your email address: UptimeWire starts monitoring after that.

== Frequently Asked Questions ==

= Do I need an UptimeWire account? Is it free? =

Yes, the plugin shows the results of your UptimeWire account. The Free plan monitors one website. Paid plans add websites and checks; see https://uptimewire.com/pricing/.

= Does disconnecting or deleting the plugin stop monitoring? =

No. Disconnecting (UptimeWire › Settings) only ends the WordPress connection: WordPress stops showing results. Deactivating changes nothing and keeps the connection. Deleting removes all plugin data from the database (on every site of a network) after asking UptimeWire to end the connection. In all cases UptimeWire keeps monitoring and sending alerts. To stop monitoring, pause or delete the website in UptimeWire.

= Can I monitor a local or staging site? =

UptimeWire checks websites from the internet, so local sites (localhost, .local, .test, private addresses) can't be monitored. Public staging sites can.

= UptimeWire says it couldn't confirm my website. What now? =

Cloudflare, a firewall, a security plugin or a page cache that ignores query strings can block or answer the confirmation request. Allow UptimeWire's checks (https://uptimewire.com/resources/guides/allow-uptimewire-checks-through-firewall/) and exclude addresses with `uptimewire_proof` from caching. You don't need to turn the protection off.

= I copied my site to staging. What happens there? =

The plugin remembers the WordPress address on which the connection was made. A copy on another address shows "This WordPress Site Is a Copy": it shows no monitoring data, never uses or ends the original's connection, and can remove the copied connection locally. A copy on the same address, such as a local copy reached through the hosts file, counts as the same site, and so does a copy on the same address with or without "www." (a site connected on example.com and its copy on www.example.com, or the other way round): disconnecting or deleting the plugin there ends the original's connection. Deactivate the plugin on such copies instead.

= Who can see the plugin screens? =

Administrators (`manage_options`). The filters `uptimewire_capability` (who may view the results) and `uptimewire_manage_capability` (who may also connect and disconnect) change this. Managing needs both.

= Where is the credential stored? Can I keep it out of the database? =

In the option `uptimewire_connection`, which is not loaded on every page and never shown. To keep it in `wp-config.php`, copy it once with WP-CLI (`wp option pluck uptimewire_connection token`) and add `define( 'UPTIMEWIRE_TOKEN', '…' );`. The plugin then uses the constant and removes the copy from the database. On a multisite network the constant counts for the main site only. After disconnecting, remove the line yourself.

= Which data does the plugin keep in WordPress? =

The connection, the last status received from UptimeWire (option `uptimewire_status`) and short-lived connection state. It creates no tables and schedules no tasks.

= Where do I get help? =

Tools › Site Health › Info › UptimeWire has the details support needs, without the credential. Write to support@uptimewire.com or use the WordPress.org support forum of this plugin. Report security problems privately to support@uptimewire.com.

== Screenshots ==

1. Overview before connecting: start free monitoring or connect an existing account.
2. Connecting: approve in UptimeWire, with the same code shown in WordPress.
3. Overview of a connected website: status, checks, uptime and recent events.
4. The Dashboard widget "UptimeWire — Website Status".
5. UptimeWire tests in Tools › Site Health.
6. Settings: connection, monitoring, alert readiness and Disconnect.

== Changelog ==

= 1.0.0 =
* First public release.
* Connect a site to UptimeWire by code (OAuth 2.0 Device Authorization, RFC 8628), with a site-scoped, read-only credential.
* Overview, Dashboard widget, Site Health tests and Info, Settings.
* Uptime, SSL certificate, domain expiration, DNS, email domain and scheduled job results.
* Disconnect, copy detection, separate view and manage capabilities, clean uninstall.

== Upgrade Notice ==

= 1.0.0 =
First public release.

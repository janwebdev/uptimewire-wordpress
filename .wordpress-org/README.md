# WordPress.org directory images

These files go to the `assets/` folder of the plugin's WordPress.org SVN repository, next to `trunk/`. They are not part of the plugin archive (`.distignore`).

| File | What |
|---|---|
| `icon-128x128.png`, `icon-256x256.png` | The UptimeWire shield, the approved logo (`brain/design/uptimewire-shield.png`, 1500×1500), cropped to the shield and centred with a small margin. |
| `banner-772x250.png`, `banner-1544x500.png` | The shield and the name "UptimeWire" in Onest Bold, as in the header of uptimewire.com, on the site's dark panel colour `#181c32` with the brand orange `#f36e04`. Rendered from `banner-source.html` at scale 1 and 2. |
| `screenshot-N.png` | Real screens of the plugin, numbered as in the `== Screenshots ==` section of `readme.txt`. |

To render the banners again, put `shield.png` (the cropped shield) and `onest-latin-wght-normal.woff2` (from the website, SIL Open Font License 1.1) next to `banner-source.html` and screenshot it at 772×250 with device scale 1 and 2.

The UptimeWire name and logo are trademarks of their owner; the GPL licence of the plugin does not cover them.

=== Reviews (Google/Yelp/Facebook) ===
Contributors: you
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gutenberg block to display reviews from Google Places, Yelp Fusion, and Facebook Page ratings. Server-side fetching, caching, and a settings page for IDs/keys.

== Description ==
- Add **Settings → Reviews**: paste your business IDs/URLs and API keys.
- Insert the **Business Reviews** block and choose providers, layout, and count.
- Data is fetched server-side (keys never exposed) and cached (default 6h).

== Installation ==
1. Upload the `reviews` folder to `/wp-content/plugins/`.
2. `npm install` then `npm run build`.
3. Activate plugin.
4. Go to Settings → Reviews and configure IDs/keys.
5. Insert the block into any post/page.

== Notes ==
- Google: enable Places API; use Place Details endpoint permission.
- Yelp: create Fusion API key; Business ID is in the business URL.
- Facebook: requires a Page access token and appropriate app permissions; your Page must have reviews enabled. Availability varies by account.

== Changelog ==
= 1.0.0 =
* Initial release.


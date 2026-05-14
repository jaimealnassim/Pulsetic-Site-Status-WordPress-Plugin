=== Pulsetic Speed Widget ===
Contributors:      nahnumedia
Tags:              pulsetic, speed, uptime, performance, monitoring, cache
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      7.4
Stable tag:        1.4.0
License:           GPL-2.0+

Live load times and uptime from Pulsetic. Two shortcodes. API key stays server-side. Full cache plugin compatibility built-in.

== Description ==

Two shortcodes:

= [pulsetic_speed] =
`[pulsetic_speed monitor_id="123" theme="dark" preview="4" show_modal="yes" minutes="30" note="* Stats for nahnumedia.com"]`

= [pulsetic_uptime] =
`[pulsetic_uptime monitor_id="123" days="30" theme="dark" style="card" note="* Stats for nahnumedia.com"]`

== Cache Plugin Compatibility ==

Fully automatic — no manual configuration required.

Supported: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize, Comet Cache, Bunny CDN / WP Edge Cache.

Strategy:
- Shortcode HTML renders skeleton-only — safe to page-cache (data loads via JS)
- REST endpoints always return no-cache headers (Cache-Control, CDN-Cache-Control, Bunny-Cache-Control, X-Accel-Expires, etc.)
- DONOTCACHEPAGE / DONOTMINIFY defined on PSW REST requests
- JS file excluded from WP Rocket / LiteSpeed / Autoptimize combine/defer

== Changelog ==

= 1.3.0 =
* NEW: class-psw-cache.php — full cache plugin compatibility layer
* Automatic REST endpoint exclusion for WP Rocket, LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache, Autoptimize, Comet Cache
* No-cache headers on all /wp-json/psw/* responses including CDN-specific variants
* DONOTCACHEPAGE + DONOTMINIFY + DONOTCACHEDB defined on PSW REST requests
* Admin settings page shows detected active cache plugins with green confirmation panel

= 1.2.0 =
* Added [pulsetic_uptime] shortcode
* Added note attribute for * disclaimer footnotes
* Added /wp-json/psw/v1/uptime REST endpoint

= 1.1.0 =
* Default preview 4 locations
* Added modal with region grouping

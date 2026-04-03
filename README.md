# Pulsetic Site Status

> A WordPress plugin that displays the live up/down status of your monitored sites via the [Pulsetic](https://pulsetic.com) API. Supports multiple monitor groups, three frontend display styles, live polling via the WP REST API, and full ACSS / CSS custom property theming.

---

## Features

- **Three shortcode styles** — list, card grid, and pill bar
- **Live polling via REST API** — `GET /wp-json/pulsetic/v1/status/{group}` with HTTP cache headers; falls back to `admin-ajax` automatically on hosts that block `/wp-json/`
- **Configurable scan interval** — 1 min to 1 hour, validated server-side against a strict allowlist
- **Smart caching** — transient cache with in-process deduplication and stale-while-revalidate background refresh via WP-Cron
- **Full CSS token support** — all color and size settings accept `#hex`, `var(--acss-token)`, `rgba()`, `hsl()`, named colors, `em`, `px`, `clamp()`, etc.
- **Per-monitor labels and links** — override the display name and wrap each item in a custom URL
- **Multiple groups** — create as many groups as you need; each group's shortcode preview shows all three styles with a tab switcher
- **Accessibility** — `role="list"` on list widgets, `aria-label` on every item, updated live on status change
- **Zero frontend dependencies** — vanilla JS, no jQuery, no external libraries on the front end

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A [Pulsetic](https://pulsetic.com) account with an API token

---

## Installation

1. Download the latest release zip from the [Releases](../../releases) page.
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Activate Plugin**.
4. Navigate to **Settings → Pulsetic Status** to configure.

---

## Configuration

### 1 · API Token & Scan Interval

Go to **Settings → Pulsetic Status → ① API Token & Scan Interval**.

Paste your token from [app.pulsetic.com/account/api](https://app.pulsetic.com/account/api) and choose how often the plugin should re-check the Pulsetic API:

| Interval | Best for |
|---|---|
| 1 minute | High-priority monitors where you need near-realtime status |
| 2 – 5 minutes | Most sites — good balance of freshness vs. API calls |
| 10 – 30 minutes | Low-traffic or informational status pages |
| 1 hour | Sites checked rarely; minimises API quota usage |

> **Note:** The scan interval controls the server-side cache TTL. The frontend `refresh_interval` shortcode attribute is separate — it controls how often the browser re-reads the cached data, not how often the plugin contacts Pulsetic.

### 2 · Monitor Groups

Each group maps to one set of shortcodes. Create as many as you need.

| Field | Description |
|---|---|
| **Group name** | Human-readable name — auto-generates the slug |
| **Shortcode preview** | Tab between List / Cards / Bar to copy the right shortcode |
| **Monitors** | Check the monitors to include in this group |
| **Label** | Optional display name override per monitor |
| **Link** | Optional URL — wraps the monitor name in an `<a>` tag |

### 3 · Colors

All fields accept any valid CSS color value:

```
#22c55e
var(--color-success)
rgba(34, 197, 94, 0.9)
hsl(142, 71%, 45%)
green
```

### 4 · Sizes

All fields accept any valid CSS size value:

```
10px
0.8em
var(--space-xs)
var(--text-xs)
clamp(8px, 1vw, 12px)
```

---

## Shortcodes

### `[pulsetic_status]` — List

Inline flex list. Items sit next to each other and wrap on narrow screens. Best for sidebars, footers, and inline text.

```
[pulsetic_status group="my-group"]
```

| Attribute | Default | Description |
|---|---|---|
| `group` | `default` | Group slug |
| `refresh_interval` | `60` | Seconds between polls. `0` disables polling. |
| `label_online` | `Online` | Badge text when monitor is up |
| `label_offline` | `Offline` | Badge text when monitor is down |
| `label_paused` | `Paused` | Badge text when monitor is paused |
| `show_name` | `true` | Show the site label |
| `show_url` | `false` | Show the raw URL |
| `show_refresh` | `false` | Show a cache countdown timer |

---

### `[pulsetic_cards]` — Card Grid

Each monitor renders as a card with a coloured left-border accent and a status badge. Best for dedicated status pages.

```
[pulsetic_cards group="my-group"]
```

| Attribute | Default | Description |
|---|---|---|
| `group` | `default` | Group slug |
| `refresh_interval` | `60` | Seconds between polls. `0` disables polling. |
| `label_online` | `Online` | Badge text when monitor is up |
| `label_offline` | `Offline` | Badge text when monitor is down |
| `label_paused` | `Paused` | Badge text when monitor is paused |
| `show_name` | `true` | Show the site label |
| `show_url` | `false` | Show the raw URL below the name |

---

### `[pulsetic_bar]` — Pill Bar

Compact horizontal row of colour-coded pills. Best for headers, footers, or "all systems go" indicators.

```
[pulsetic_bar group="my-group"]
```

| Attribute | Default | Description |
|---|---|---|
| `group` | `default` | Group slug |
| `refresh_interval` | `60` | Seconds between polls. `0` disables polling. |
| `label_online` | `Online` | Status text (used in `aria-label`) |
| `label_offline` | `Offline` | Status text |
| `label_paused` | `Paused` | Status text |
| `show_name` | `true` | Show the site label inside the pill |
| `show_status` | `false` | Also show status text inside the pill (e.g. `· Online`) |

---

## Examples

**Arabic labels:**
```
[pulsetic_status group="main" label_online="متصل" label_offline="معطل" label_paused="متوقف"]
```

**Status page with cards, no auto-refresh:**
```
[pulsetic_cards group="services" refresh_interval="0"]
```

**Footer pill bar that refreshes every 2 minutes:**
```
[pulsetic_bar group="footer-sites" refresh_interval="120"]
```

**Show raw URLs in the list:**
```
[pulsetic_status group="main" show_url="true"]
```

---

## How Polling Works

When `refresh_interval` is greater than `0`, each shortcode registers itself for live polling. The browser polls on the configured interval and updates only the DOM elements whose status has changed.

**Poll request flow:**

```
Browser interval fires
        │
        ▼
GET /wp-json/pulsetic/v1/status/{group}   ← REST API (preferred)
        │
        ├─ Success ──► applyDiff() — update only changed items
        │
        └─ Fail (host blocks /wp-json/, network error, etc.)
                │
                ▼
        POST admin-ajax.php?action=pulsetic_poll_group   ← fallback
                │
                └─ applyDiff() — same diff engine, same payload shape
```

**Key behaviours:**

- **REST preferred** — GET requests are cacheable; the endpoint sets `Cache-Control: public, max-age=N, stale-while-revalidate=N` so CDNs and browsers can cache them naturally
- **admin-ajax fallback** — automatic, transparent, no configuration needed
- **Cache-backed** — neither endpoint forces a live Pulsetic API call; they read from the WP transient cache so visitor polls can never exhaust your API quota
- **Background refresh** — when the transient nears expiry (within 10% of TTL), a WP-Cron single event refreshes it in the background so no visitor ever blocks on a live fetch
- **In-flight guard** — if a poll hasn't returned before the next interval fires, that tick is skipped rather than stacking requests
- **Tab visibility** — polling pauses when the browser tab is hidden and resumes with an immediate poll when visible again
- **Staggered start** — multiple widgets jitter their start time by up to 5 s to spread server load
- **DOM diffing** — only changed elements are touched; focus, scroll position, and layout are preserved

---

## REST API

The plugin exposes a public read-only endpoint:

```
GET /wp-json/pulsetic/v1/status/{group}
```

**Response:**
```json
{
  "items": [
    {
      "id": "12345",
      "status": "online",
      "display_name": "Main Website",
      "url": "https://example.com",
      "custom_link": ""
    }
  ],
  "cache_ttl": 243
}
```

`status` is always one of `online`, `offline`, or `paused`. `cache_ttl` is the number of seconds until the server-side cache expires.

The endpoint requires no authentication — the data shown is already public on your frontend. It validates the `{group}` parameter against your configured groups and returns `400` for unknown slugs.

---

## File Structure

```
pulsetic-site-status/
├── pulsetic-site-status.php        # Plugin bootstrap + header
├── uninstall.php                   # Removes all data on plugin deletion
├── README.md                       # This file
├── assets/
│   ├── css/
│   │   ├── admin.css               # Settings page styles
│   │   └── frontend.css            # All three widget styles + error states
│   └── js/
│       ├── admin.js                # Settings page — groups, colors, tabs
│       └── frontend.js             # REST-first polling engine with ajax fallback
└── includes/
    ├── functions.php               # Shared helpers, sanitizers, option cache, enqueue
    ├── class-api.php               # Pulsetic API + transient/runtime caching
    ├── class-admin.php             # Admin menu, enqueue, settings save
    ├── class-ajax.php              # REST route + admin-ajax handlers
    ├── class-shortcode.php         # [pulsetic_status] list style
    ├── class-shortcode-cards.php   # [pulsetic_cards] card grid style
    ├── class-shortcode-bar.php     # [pulsetic_bar] pill bar style
    └── views/
        └── admin-page.php          # Settings page HTML template
```

---

## Caching Architecture

```
Browser poll request
        │
        ▼
Pulsetic_API::get_monitors()
        │
        ├─ $runtime_cache set? ──yes──► return  (0 DB hits, 0 API calls)
        │
        ├─ WP transient exists? ──yes──► populate runtime cache
        │         │                      TTL < stale_window? → schedule Cron refresh
        │         └─────────────────────► return
        │
        └─ Live fetch from Pulsetic API
                  │
                  └─ Store in transient (scan_interval seconds)
                     Store in runtime cache
                     return
```

---

## Uninstall

When you delete the plugin via **Plugins → Delete**, `uninstall.php` automatically removes:

- All plugin options: `pulsetic_api_token`, `pulsetic_colors`, `pulsetic_groups`, `pulsetic_sizes`, `pulsetic_scan_interval`
- The monitor cache transient: `pulsetic_monitors_cache`
- All scheduled WP-Cron background refresh events

Deactivating the plugin (without deleting) leaves all data intact so you can reactivate without reconfiguring.

---

## Security

| Concern | Approach |
|---|---|
| Settings form | Nonce via `wp_nonce_field` + `check_admin_referer` |
| Admin AJAX | Nonce verified before capability check; unauthorized returns HTTP 403 |
| REST endpoint | Public GET — data is already displayed publicly; group slug validated against allowlist |
| `$_POST` values | `wp_unslash()` + per-field sanitizer (`sanitize_text_field`, `sanitize_key`, `esc_url_raw`) |
| Scan interval | Validated against strict integer allowlist — no arbitrary TTL accepted |
| CSS value inputs | `pulsetic_sanitize_css_value()` — allowlist regex, blocks `javascript:`, `expression()`, `@import` |
| CSS output | Re-sanitized via `pulsetic_build_css_vars()` before writing to `<style>` (defence-in-depth) |
| HTML output | `esc_html()`, `esc_attr()`, `esc_url()` on all user-facing output |
| Inline JS config | `wp_json_encode()` with all four `JSON_HEX_*` flags — prevents `</script>` injection |
| Admin redirect | `wp_safe_redirect()` — prevents open redirect |
| Page render | `current_user_can('manage_options')` checked on both save and render |

---

## Changelog

### 1.1.3
- **REST API endpoint** — `GET /wp-json/pulsetic/v1/status/{group}` with `Cache-Control` headers; group slug validated, returns 400 for unknown groups
- **REST-first polling** — `frontend.js` tries the REST endpoint first, falls back to `admin-ajax` transparently if the host blocks `/wp-json/`
- **Shared payload builder** — both REST and admin-ajax use the same `build_poll_payload()` method; response shape guaranteed identical
- **Group shortcode preview tabs** — each group now shows a List / Cards / Bar tab switcher so you can copy the right shortcode; tabs are colour-coded and update live as you type the group name
- **`role="list"`** on `[pulsetic_status]` `<ul>` — restores list semantics in Safari + VoiceOver (removed by `list-style: none`)
- **Error/empty state CSS** — `.pulsetic-error` and `.pulsetic-empty` classes now have explicit styles instead of falling through to theme defaults
- **Complete plugin header** — added `Requires at least: 6.0`, `Requires PHP: 8.0`, `License`, `License URI`, `Text Domain`, `Author URI`, `Plugin URI`

### 1.1.2
- Configurable **Scan Interval** setting (1 min – 1 hour), validated against strict allowlist
- Changing interval immediately busts the existing cache
- Stale-while-revalidate window now scales proportionally with TTL (10%, clamped 30 s – 120 s)
- "Next refresh in X" countdown shown next to the selector

### 1.1.1
- `wp_safe_redirect()` replaces `wp_redirect()`
- Unauthorized AJAX returns HTTP 403
- `wp_unslash()` added to all `$_POST` reads
- CSS custom property values re-sanitized at output via `pulsetic_build_css_vars()`
- `wp_json_encode()` uses full `JSON_HEX_*` flags
- In-request option cache (`pulsetic_get_option()`) eliminates repeated DB calls
- Shared `pulsetic_enqueue_frontend_assets()` replaces duplicate `maybe_enqueue()` in card/bar classes
- `enqueue_assets()` skips monitor fetch when no token configured
- Null return from `pulsetic_find_group()` handled explicitly
- Widget DOM IDs include style prefix to prevent same-page collisions
- Inline `style=` removed from cards shortcode — moved to CSS
- `setInterval` cleared on `visibilitychange`
- `uninstall.php` added

### 1.1.0
- Three shortcode styles: `[pulsetic_status]`, `[pulsetic_cards]`, `[pulsetic_bar]`
- Color and size inputs accept any CSS value (`var()`, `rgba()`, ACSS tokens, etc.)
- Admin CSS/JS extracted to enqueued asset files
- Live polling with DOM diffing and pulse animation on change
- Stale-while-revalidate background refresh via WP-Cron

### 1.0.0
- Initial release — single-file plugin
- Pulsetic API integration with transient cache
- Admin settings: token, monitor groups, custom labels, color picker
- `[pulsetic_status]` shortcode

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)

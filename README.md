# Pulsetic Site Status

> A WordPress plugin that displays the live up/down status of your monitored sites via the [Pulsetic](https://pulsetic.com) API. Supports multiple monitor groups, three frontend display styles, live AJAX polling, and full ACSS / CSS custom property theming.

---

## Features

- **Three shortcode styles** — list, card grid, and pill bar
- **Live AJAX polling** — status updates without a page reload, configurable interval
- **Smart caching** — 5-minute transient cache with in-process deduplication and background WP-Cron refresh
- **Full CSS token support** — all color and size settings accept `#hex`, `var(--acss-token)`, `rgba()`, `hsl()`, named colors, `em`, `px`, `clamp()`, etc.
- **Per-monitor labels and links** — override the display name and wrap each item in a custom URL
- **Multiple groups** — create as many groups as you need, each with its own shortcode slug
- **Accessibility** — all widgets include `aria-label` attributes that update live with status changes
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

### 1 · API Token &amp; Scan Interval

Go to **Settings → Pulsetic Status → ① API Token &amp; Scan Interval**.

Paste your token from [app.pulsetic.com/account/api](https://app.pulsetic.com/account/api) and choose how often the plugin should re-check the Pulsetic API:

| Interval | Best for |
|---|---|
| 1 minute | High-priority monitors where you need near-realtime status |
| 2 – 5 minutes | Most sites — good balance of freshness vs. API calls |
| 10 – 30 minutes | Low-traffic or informational status pages |
| 1 hour | Sites checked rarely; minimises API quota usage |

> **Note:** The interval controls the server-side cache TTL. The frontend AJAX polling (`refresh_interval` shortcode attribute) is separate — it re-reads the cached data, not the live API.

### 2 · Monitor Groups

Each group maps to one shortcode. You can create as many groups as you need.

| Field | Description |
|---|---|
| **Group name** | Human-readable name — auto-generates the slug |
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

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `group` | `default` | Group slug |
| `refresh_interval` | `60` | Seconds between AJAX polls. `0` disables polling. |
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

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `group` | `default` | Group slug |
| `refresh_interval` | `60` | Seconds between AJAX polls. `0` disables polling. |
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

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `group` | `default` | Group slug |
| `refresh_interval` | `60` | Seconds between AJAX polls. `0` disables polling. |
| `label_online` | `Online` | Status text (used in `aria-label`) |
| `label_offline` | `Offline` | Status text |
| `label_paused` | `Paused` | Status text |
| `show_name` | `true` | Show the site label inside the pill |
| `show_status` | `false` | Also show status text inside the pill (e.g. `· Online`) |

---

## Examples

**Arabic labels (RTL-friendly):**
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

When `refresh_interval` is greater than `0`, the shortcode registers the widget for live AJAX polling via `frontend.js`. The browser calls `admin-ajax.php` on the configured interval to check for status changes.

Key behaviours:

- **Cache-backed** — the AJAX endpoint reads from the WP transient cache (5-minute TTL). It never forces a live Pulsetic API call, so visitors cannot hammer your API quota.
- **Background refresh** — when the transient is within 60 seconds of expiring, a WP-Cron single event fires to refresh it in the background before the next visitor needs it.
- **In-flight guard** — if a poll request hasn't returned before the next interval fires, the interval tick is skipped rather than stacking requests.
- **Tab visibility** — polling pauses automatically when the browser tab is hidden and resumes (with an immediate poll) when it becomes visible again.
- **Staggered start** — multiple widgets on the same page start their poll loops with a random jitter (up to 5 s) so they don't all hit the server simultaneously.
- **DOM diffing** — only the specific elements whose status has changed are updated. No full re-renders.

---

## File Structure

```
pulsetic-site-status/
├── pulsetic-site-status.php        # Plugin bootstrap
├── uninstall.php                   # Cleans all data on plugin deletion
├── assets/
│   ├── css/
│   │   ├── admin.css               # Settings page styles
│   │   └── frontend.css            # All three widget styles
│   └── js/
│       ├── admin.js                # Settings page interactions
│       └── frontend.js             # AJAX polling engine
└── includes/
    ├── functions.php               # Shared helpers, sanitizers, option cache
    ├── class-api.php               # Pulsetic API + transient/runtime caching
    ├── class-admin.php             # Admin menu, enqueue, settings save
    ├── class-ajax.php              # AJAX handlers (admin refresh + frontend poll)
    ├── class-shortcode.php         # [pulsetic_status] list style
    ├── class-shortcode-cards.php   # [pulsetic_cards] card grid style
    ├── class-shortcode-bar.php     # [pulsetic_bar] pill bar style
    └── views/
        └── admin-page.php          # Settings page HTML template
```

---

## Caching Architecture

```
Browser request
      │
      ▼
Pulsetic_API::get_monitors()
      │
      ├─ Static $runtime_cache set?  ──yes──► return (0 DB hits)
      │
      ├─ WP transient exists?  ──yes──► store in runtime cache
      │         │                       check stale window (< 60 s left)
      │         │                       schedule WP-Cron refresh if stale
      │         └────────────────────► return
      │
      └─ Live fetch from Pulsetic API
                │
                └─ Store in transient (5 min) + runtime cache
                   return
```

---

## Uninstall

When you delete the plugin via **Plugins → Delete**, `uninstall.php` automatically removes:

- All plugin options (`pulsetic_api_token`, `pulsetic_colors`, `pulsetic_groups`, `pulsetic_sizes`, `pulsetic_scan_interval`)
- The monitor cache transient (`pulsetic_monitors_cache`)
- Any scheduled WP-Cron background refresh events

Deactivating the plugin (without deleting) leaves all data intact so you can reactivate without reconfiguring.

---

## Security

| Concern | Approach |
|---|---|
| Settings form | Nonce via `wp_nonce_field` + `check_admin_referer` |
| AJAX endpoints | Nonce verified before capability check on both handlers |
| Unauthorized AJAX | Returns HTTP `403` with JSON error body |
| `$_POST` values | `wp_unslash()` + per-field sanitizer (`sanitize_text_field`, `sanitize_key`, `esc_url_raw`) |
| CSS value inputs | `pulsetic_sanitize_css_value()` — allows valid CSS tokens, blocks `javascript:`, `expression()`, `@import`, HTML/JS injection chars |
| CSS output | Values re-sanitized via `pulsetic_build_css_vars()` before writing to `<style>` tag |
| HTML output | `esc_html()`, `esc_attr()`, `esc_url()` on all user-facing output |
| Inline JS config | `wp_json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` |
| Admin redirect | `wp_safe_redirect()` to prevent open redirect |
| Render access | `current_user_can('manage_options')` checked on both save and render |

---

## Changelog

### 1.1.2 — Configurable scan interval
- New **Scan Interval** setting in the Token card — choose from 1 min, 2 min, 5 min, 10 min, 15 min, 30 min, or 1 hour
- Interval validated against a strict allowlist server-side (no arbitrary TTL injection)
- Changing the interval immediately busts the existing cache so the new TTL applies on the next fetch
- Stale-while-revalidate window scales with TTL (10%, clamped 30 s – 120 s) instead of being hardcoded at 60 s
- "Next refresh in X" live countdown shown next to the selector
- `pulsetic_scan_interval` option cleaned up by `uninstall.php`

### 1.1.1 — Code quality & security audit
- `wp_safe_redirect()` replaces `wp_redirect()`
- Unauthorized AJAX returns HTTP 403
- `wp_unslash()` added to all `$_POST` reads
- CSS custom property values re-sanitized at output via `pulsetic_build_css_vars()`
- `wp_json_encode()` now uses full JSON_HEX flags to prevent `</script>` injection
- In-request option cache (`pulsetic_get_option()`) removes repeated `get_option()` DB calls
- Duplicate `maybe_enqueue()` code in card/bar classes replaced with shared `pulsetic_enqueue_frontend_assets()`
- `enqueue_assets()` skips monitor fetch when no API token is configured
- Null return from `pulsetic_find_group()` handled explicitly in all shortcodes
- Widget DOM IDs now include style prefix to prevent collisions on same-page multi-style usage
- Inline `style=` removed from cards shortcode — moved to CSS
- `setInterval` cleared on `visibilitychange` (tab hidden) to prevent timer accumulation
- `uninstall.php` added — deletes all options, transients, and cron events on plugin deletion

### 1.1.0 — Feature update
- Three shortcode styles: `[pulsetic_status]`, `[pulsetic_cards]`, `[pulsetic_bar]`
- Color inputs now accept any CSS value (`var()`, `rgba()`, ACSS tokens, etc.)
- New size settings for dot size, item font size, badge font size
- Admin CSS/JS extracted from inline to enqueued asset files
- Live AJAX polling with DOM diffing and pulse animation
- Stale-while-revalidate background cache refresh via WP-Cron

### 1.0.0 — Initial release
- Single-file plugin
- Pulsetic API integration with 5-minute transient cache
- Admin settings: token, monitor groups, custom labels, color picker
- `[pulsetic_status]` shortcode

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)

# Team51 Cache Control

Tunes Batcache TTLs on WP Cloud (Pressable / Atomic) for sites Team 51 manages.

By default, every page on a WP Cloud site is cached for 5 minutes (`max_age=300`) and only stored after 2 hits in 120 seconds. That's conservative — appropriate for unknown sites, but often too short for content sites where the bulk of baseline traffic is on long-tail older articles, archives, and feeds that change rarely.

This plugin raises TTLs in tiers based on what the page is and how recently it was edited. It never lowers TTL anywhere.

## Tiers

| Page type | Default TTL | Notes |
| --- | --- | --- |
| Recent posts (< 1 week old, by `post_modified`) | 5 min (platform default) | Preserves freshness for new content. |
| Mid-age posts (1 week → 1 year) | 1 hour | Caches on first hit (`times=1`). |
| Old posts (> 1 year) | 24 hours | Caches on first hit (`times=1`). |
| Feeds (RSS / Atom) | 1 hour | RSS readers poll on their own intervals. |
| Archives (category, tag, author, date) | 30 minutes | |

All values are configurable from **Settings → Cache Control**.

## Why long TTLs are safe

WP Cloud's Edge Cache mu-plugin (`/wp-content/mu-plugins/edge-cache/shared/class-edge-cache-purge.php`) hooks `transition_post_status` and on every post save / update / publish / unpublish purges the following from **both** Batcache and the 30-POP Edge Cache:

- The post permalink (and paginated subpages)
- Home page
- All category / tag / custom-taxonomy archives the post belongs to
- All term feeds, plus the comments feed
- Author archive and author feed

Long TTLs only ever apply to URLs where nothing has changed.

## Settings screen

`Settings → Cache Control` (WP admin) — accessible to users with `manage_options`.

- **Enable plugin** — checkbox. Unchecking reverts the entire site to the platform default (5 min, `times=2`). Useful for A/B comparison or troubleshooting.
- **Feeds** — select TTL.
- **Archives** — select TTL.
- **Older posts (over 1 week)** — configurable threshold + TTL.
- **Archive posts (over 1 year)** — configurable threshold + TTL.
- **Reset to defaults** button at the bottom of the page.

TTL and threshold options are presented as dropdowns with a fixed allowed set (5 min through 1 week for TTLs; 1 day through 2 years for thresholds). Direct option pokes outside that range are rejected by the sanitizer.

The sanitizer also enforces ordering: the "old" threshold must be ≥ the "mid" threshold, and the "old" TTL must be ≥ the "mid" TTL.

## Filter

```php
apply_filters( 'team51_cache_control_ttl', int $ttl, array $settings );
```

Lets you adjust the TTL for the current request from another mu-plugin if needed (e.g. force a shorter TTL on a specific URL during a high-stakes editorial event).

## Installation

Upload the plugin folder to `/wp-content/plugins/team51-cache-control/` and activate.

The plugin can also be loaded as a mu-plugin by symlinking or copying `team51-cache-control.php` into `/wp-content/mu-plugins/` — the "Enable plugin" toggle in the settings still works (the plugin reads the option on every request).

## Verification

After activation, hit a post URL twice within 2 minutes (Batcache requires `times` hits to store). Then check the Batcache footer comment:

```bash
curl -s https://example.com/some-old-article/ | tail -6
```

Expected output (the "expires in" value reflects the tier-applied TTL):

```html
<!--
	generated 14 seconds ago
	generated in 0.412 seconds
	served from batcache in 0.002 seconds
	expires in 86386 seconds
-->
```

For old posts you should see `expires in ~86400 seconds` (24h). For feeds, `~3600` (1h).

You can also confirm via the response header:

```
Cache-Control: max-age=86400, must-revalidate
```

## Rollback

Either deactivate from the plugin list, or uncheck "Enable plugin" on the settings page. Both revert behavior immediately for new requests; existing cached entries expire naturally (max 24h with default tiers).

<?php
/**
 * Cache Control logic — applies tiered TTLs to Batcache.
 *
 * @package Team51_Cache_Control
 */

namespace Team51_Cache_Control;

defined( 'ABSPATH' ) || exit;

/**
 * Adjusts $batcache->max_age based on the current request context.
 *
 * Modifying $batcache->max_age at the `wp` action is safe: Batcache's
 * `advanced-cache.php` reads max_age in its output-buffer callback at
 * end-of-request, not at load time. The hook fires after the main query
 * is parsed, so is_feed() / is_singular() / is_category() etc. are reliable.
 *
 * Editorial freshness is preserved by WP Cloud's
 * /wp-content/mu-plugins/edge-cache/shared/class-edge-cache-purge.php,
 * which hooks transition_post_status and on every save/publish/unpublish
 * purges (across both Batcache and the 30-POP Edge Cache):
 *   - Post permalink and paginated subpages
 *   - Home page
 *   - Category, tag, author, and custom-taxonomy archives the post belongs to
 *   - All term feeds and the comments feed
 *
 * Long TTLs only ever apply to URLs where nothing has changed.
 */
final class Cache_Control {

	/**
	 * @var Cache_Control|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp', array( $this, 'apply_ttl' ), 10 );
	}

	/**
	 * Decide the correct TTL for the current request and apply it.
	 */
	public function apply_ttl(): void {
		global $batcache;

		// Sanity: Batcache may be an object (WP Cloud) or array (legacy/local).
		if ( ! is_object( $batcache ) && ! is_array( $batcache ) ) {
			return;
		}

		$settings = \team51_cache_control_get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// Path-based exclusions: skip TTL adjustments entirely for matched URLs.
		// They fall back to the platform default (5 min, times=2).
		if ( $this->is_excluded( $settings ) ) {
			return;
		}

		$ttl   = null;
		$times = null;

		if ( is_feed() ) {
			$ttl = (int) $settings['feed_seconds'];
		} elseif ( is_singular( 'post' ) ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$age = time() - (int) get_post_modified_time( 'U', true, $post );

				if ( $age > (int) $settings['post_old_threshold'] ) {
					$ttl   = (int) $settings['post_old_seconds'];
					$times = 1;
				} elseif ( $age > (int) $settings['post_mid_threshold'] ) {
					$ttl   = (int) $settings['post_mid_seconds'];
					$times = 1;
				} else {
					$ttl   = (int) $settings['post_recent_seconds'];
					$times = 1;
				}
			}
		} elseif ( is_singular( 'page' ) ) {
			$ttl   = (int) $settings['page_seconds'];
			$times = 1;
		} elseif ( is_category() || is_tag() || is_author() || is_date() ) {
			$ttl = (int) $settings['archive_seconds'];
		}

		if ( null === $ttl || $ttl <= 0 ) {
			return;
		}

		/**
		 * Filter the TTL applied to the current request.
		 *
		 * @param int   $ttl      TTL in seconds.
		 * @param array $settings Current plugin settings.
		 */
		$ttl = (int) apply_filters( 'team51_cache_control_ttl', $ttl, $settings );

		if ( $ttl <= 0 ) {
			return;
		}

		if ( is_object( $batcache ) ) {
			$batcache->max_age = $ttl;
			if ( null !== $times ) {
				$batcache->times = $times;
			}
		} else {
			$batcache['max_age'] = $ttl;
			if ( null !== $times ) {
				$batcache['times'] = $times;
			}
		}
	}

	/**
	 * Determine whether the current request path is in the user-configured
	 * exclusion list. Comparison is normalized so trailing-slash and full-URL
	 * differences don't matter.
	 *
	 * @param array<string,mixed> $settings Current plugin settings.
	 */
	private function is_excluded( array $settings ): bool {
		if ( empty( $settings['exclusions'] ) || ! is_array( $settings['exclusions'] ) ) {
			return false;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$current     = wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		if ( ! is_string( $current ) || '' === $current ) {
			$current = '/';
		}
		$current = $this->normalize_path( $current );

		foreach ( $settings['exclusions'] as $excl ) {
			if ( ! is_string( $excl ) || '' === $excl ) {
				continue;
			}
			if ( $current === $this->normalize_path( $excl ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a path for comparison: strip protocol/host if present,
	 * ensure a single leading slash, ensure a single trailing slash.
	 *
	 * `/` (homepage) is preserved as-is.
	 */
	private function normalize_path( string $path ): string {
		$parsed = wp_parse_url( $path );
		if ( is_array( $parsed ) && isset( $parsed['path'] ) ) {
			$path = $parsed['path'];
		}
		$path = '/' . trim( $path, '/' );
		if ( '/' === $path ) {
			return $path;
		}
		return $path . '/';
	}
}

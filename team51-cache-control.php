<?php
/**
 * Plugin Name:       Team51 Cache Control
 * Plugin URI:        https://github.com/a8cteam51/team51-cache-control
 * Description:       Tunes Batcache TTLs on WP Cloud - longer cache for older posts, feeds, and archives. Configurable from Settings → Cache Control.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WordPress.com Special Projects
 * Author URI:        https://wpspecialprojects.wordpress.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       team51-cache-control
 *
 * @package Team51_Cache_Control
 */

defined( 'ABSPATH' ) || exit;

define( 'TEAM51_CACHE_CONTROL_VERSION', '1.2.0' );
define( 'TEAM51_CACHE_CONTROL_OPTION', 'team51_cache_control_settings' );
define( 'TEAM51_CACHE_CONTROL_PLUGIN_FILE', __FILE__ );

/**
 * Default settings, used until/unless the user customises them.
 *
 * Each tier maps a content type to a TTL (in seconds) and an optional
 * `times` override. `times` is the number of hits in the sample window
 * required before Batcache will store the page; lowering to 1 means
 * "cache on first hit", which matters for long-tail content (old posts
 * that get crawled rarely — without times=1 they never qualify).
 *
 * The Batcache default for sites on WP Cloud is `max_age=300, times=2`.
 * We never lower max_age below that default; we only raise it.
 *
 * Reference: Newspack High Traffic Performance Guide guidance —
 *   "always remain conservative about lowering cache times, and use as
 *   high caching times as the Publisher's needs will allow."
 */
function team51_cache_control_defaults(): array {
	return array(
		'enabled'              => true,
		'post_recent_seconds'  => 5 * MINUTE_IN_SECONDS, // Newer than mid-age threshold; matches platform default.
		'post_old_seconds'     => DAY_IN_SECONDS,        // Posts older than 1 year.
		'post_old_threshold'   => YEAR_IN_SECONDS,
		'post_mid_seconds'     => HOUR_IN_SECONDS,       // Posts older than 1 week (but newer than the "old" threshold).
		'post_mid_threshold'   => WEEK_IN_SECONDS,
		'feed_seconds'         => HOUR_IN_SECONDS,
		'archive_seconds'      => 30 * MINUTE_IN_SECONDS, // category, tag, author, date archives.
	);
}

/**
 * Allowed Batcache max_age values (seconds) and human labels for the settings UI.
 *
 * @return array<int,string>
 */
function team51_cache_control_get_ttl_choices(): array {
	return array(
		5 * MINUTE_IN_SECONDS  => __( '5 minutes (platform default)', 'team51-cache-control' ),
		15 * MINUTE_IN_SECONDS => __( '15 minutes', 'team51-cache-control' ),
		30 * MINUTE_IN_SECONDS => __( '30 minutes', 'team51-cache-control' ),
		HOUR_IN_SECONDS        => __( '1 hour', 'team51-cache-control' ),
		3 * HOUR_IN_SECONDS    => __( '3 hours', 'team51-cache-control' ),
		6 * HOUR_IN_SECONDS    => __( '6 hours', 'team51-cache-control' ),
		12 * HOUR_IN_SECONDS   => __( '12 hours', 'team51-cache-control' ),
		DAY_IN_SECONDS         => __( '1 day', 'team51-cache-control' ),
		3 * DAY_IN_SECONDS     => __( '3 days', 'team51-cache-control' ),
		WEEK_IN_SECONDS        => __( '1 week', 'team51-cache-control' ),
	);
}

require_once __DIR__ . '/includes/class-cache-control.php';
require_once __DIR__ . '/includes/class-settings-page.php';

add_action( 'plugins_loaded', static function () {
	Team51_Cache_Control\Cache_Control::instance();
	if ( is_admin() ) {
		Team51_Cache_Control\Settings_Page::instance();
	}
} );

/**
 * Get current settings, merged with defaults.
 *
 * @return array<string,mixed>
 */
function team51_cache_control_get_settings(): array {
	$saved = get_option( TEAM51_CACHE_CONTROL_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( team51_cache_control_defaults(), $saved );
}

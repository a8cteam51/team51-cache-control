<?php
/**
 * Settings screen for Team51 Cache Control.
 *
 * @package Team51_Cache_Control
 */

namespace Team51_Cache_Control;

defined( 'ABSPATH' ) || exit;

final class Settings_Page {

	const PAGE_SLUG    = 'team51-cache-control';
	const OPTION_GROUP = 'team51_cache_control';

	/**
	 * @var Settings_Page|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_team51_cache_control_reset', array( $this, 'handle_reset' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TEAM51_CACHE_CONTROL_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Cache Control', 'team51-cache-control' ),
			__( 'Cache Control', 'team51-cache-control' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function add_settings_link( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'team51-cache-control' ) ) );
		return $links;
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			TEAM51_CACHE_CONTROL_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => \team51_cache_control_defaults(),
			)
		);

		add_settings_section(
			'team51_cache_control_main',
			__( 'Cache TTL configuration', 'team51-cache-control' ),
			array( $this, 'render_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Enable plugin', 'team51-cache-control' ),
			array( $this, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'team51_cache_control_main'
		);

		add_settings_field(
			'feed_seconds',
			__( 'Feeds (RSS / Atom)', 'team51-cache-control' ),
			array( $this, 'render_seconds_field' ),
			self::PAGE_SLUG,
			'team51_cache_control_main',
			array(
				'key'         => 'feed_seconds',
				'description' => __( 'How long to cache RSS / Atom feeds. Longer is fine — RSS readers poll on their own schedules.', 'team51-cache-control' ),
			)
		);

		add_settings_field(
			'archive_seconds',
			__( 'Archives (category / tag / author / date)', 'team51-cache-control' ),
			array( $this, 'render_seconds_field' ),
			self::PAGE_SLUG,
			'team51_cache_control_main',
			array(
				'key'         => 'archive_seconds',
				'description' => __( 'TTL for archive listing pages. Auto-purges when a post is added, edited, or removed from one of these archives.', 'team51-cache-control' ),
			)
		);

		add_settings_field(
			'post_recent_seconds',
			__( 'Recent posts (newer than “older posts” age threshold)', 'team51-cache-control' ),
			array( $this, 'render_seconds_field' ),
			self::PAGE_SLUG,
			'team51_cache_control_main',
			array(
				'key'         => 'post_recent_seconds',
				'description' => __( 'Single post views where the last edit is newer than the “older posts (mid)” threshold below. The default is 5 minutes, matching the platform default when this plugin does not raise TTL.', 'team51-cache-control' ),
			)
		);

		add_settings_field(
			'post_mid',
			__( 'Older posts (over 1 week)', 'team51-cache-control' ),
			array( $this, 'render_post_tier_field' ),
			self::PAGE_SLUG,
			'team51_cache_control_main',
			array(
				'threshold_key' => 'post_mid_threshold',
				'seconds_key'   => 'post_mid_seconds',
				'description'   => __( 'Posts whose last edit is older than this threshold get a longer cache. Caches on first hit (times=1) so older posts crawled rarely still benefit.', 'team51-cache-control' ),
			)
		);

		add_settings_field(
			'post_old',
			__( 'Archive posts (over 1 year)', 'team51-cache-control' ),
			array( $this, 'render_post_tier_field' ),
			self::PAGE_SLUG,
			'team51_cache_control_main',
			array(
				'threshold_key' => 'post_old_threshold',
				'seconds_key'   => 'post_old_seconds',
				'description'   => __( 'Stable archive content. Long TTL is safe — every save triggers a full purge across Batcache and Edge Cache.', 'team51-cache-control' ),
			)
		);
	}

	public function render_intro(): void {
		?>
		<p><?php esc_html_e( 'Tunes how long different kinds of pages stay in Batcache before being regenerated. Longer is generally better — every post save automatically purges the post URL and all related archives, so longer TTLs only apply to pages where nothing has changed.', 'team51-cache-control' ); ?></p>
		<p><?php esc_html_e( 'Use “Recent posts” for the freshest tier; leave it at 5 minutes if you want parity with the platform default for breaking news.', 'team51-cache-control' ); ?></p>
		<?php
	}

	public function render_enabled_field(): void {
		$settings = \team51_cache_control_get_settings();
		$name     = TEAM51_CACHE_CONTROL_OPTION . '[enabled]';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
			<?php esc_html_e( 'Apply custom cache TTLs', 'team51-cache-control' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Uncheck to disable all customizations and revert to the platform default of 5 minutes for everything. Useful for A/B comparison or troubleshooting.', 'team51-cache-control' ); ?></p>
		<?php
	}

	/**
	 * @param array<string,string> $args
	 */
	public function render_seconds_field( array $args ): void {
		$settings = \team51_cache_control_get_settings();
		$key      = $args['key'];
		$seconds  = (int) ( $settings[ $key ] ?? 0 );
		$name     = TEAM51_CACHE_CONTROL_OPTION . '[' . $key . ']';
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $this->ttl_options() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $seconds, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string,string> $args
	 */
	public function render_post_tier_field( array $args ): void {
		$settings  = \team51_cache_control_get_settings();
		$threshold = (int) ( $settings[ $args['threshold_key'] ] ?? 0 );
		$seconds   = (int) ( $settings[ $args['seconds_key'] ] ?? 0 );
		$t_name    = TEAM51_CACHE_CONTROL_OPTION . '[' . $args['threshold_key'] . ']';
		$s_name    = TEAM51_CACHE_CONTROL_OPTION . '[' . $args['seconds_key'] . ']';
		?>
		<p>
			<label>
				<?php esc_html_e( 'When the post hasn\'t been edited for at least:', 'team51-cache-control' ); ?><br>
				<select name="<?php echo esc_attr( $t_name ); ?>">
					<?php foreach ( $this->threshold_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $threshold, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Cache it for:', 'team51-cache-control' ); ?><br>
				<select name="<?php echo esc_attr( $s_name ); ?>">
					<?php foreach ( $this->ttl_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $seconds, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Allowed TTL values (seconds → label). Keeps things bounded and prevents
	 * absurd values via direct option editing.
	 *
	 * Floor is 5 minutes (the platform default) — this plugin never lowers TTL.
	 *
	 * @return array<int,string>
	 */
	private function ttl_options(): array {
		return \team51_cache_control_get_ttl_choices();
	}

	/**
	 * Allowed threshold values for "post age" tier boundaries.
	 *
	 * @return array<int,string>
	 */
	private function threshold_options(): array {
		return array(
			DAY_IN_SECONDS       => __( '1 day', 'team51-cache-control' ),
			3 * DAY_IN_SECONDS   => __( '3 days', 'team51-cache-control' ),
			WEEK_IN_SECONDS      => __( '1 week', 'team51-cache-control' ),
			2 * WEEK_IN_SECONDS  => __( '2 weeks', 'team51-cache-control' ),
			MONTH_IN_SECONDS     => __( '1 month', 'team51-cache-control' ),
			3 * MONTH_IN_SECONDS => __( '3 months', 'team51-cache-control' ),
			6 * MONTH_IN_SECONDS => __( '6 months', 'team51-cache-control' ),
			YEAR_IN_SECONDS      => __( '1 year', 'team51-cache-control' ),
			2 * YEAR_IN_SECONDS  => __( '2 years', 'team51-cache-control' ),
		);
	}

	/**
	 * Sanitize and validate settings before saving.
	 *
	 * Rejects values that aren't in our allowed lists (so direct option pokes
	 * can't break the site with invalid TTLs) and enforces threshold ordering
	 * (mid threshold must be < old threshold).
	 *
	 * @param mixed $input Raw posted data.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		$defaults  = \team51_cache_control_defaults();
		$valid_ttl = array_keys( $this->ttl_options() );
		$valid_thr = array_keys( $this->threshold_options() );

		$out = array();

		$out['enabled'] = ! empty( $input['enabled'] );

		foreach ( array( 'feed_seconds', 'archive_seconds', 'post_recent_seconds', 'post_mid_seconds', 'post_old_seconds' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;
			$out[ $key ] = in_array( $value, $valid_ttl, true ) ? $value : $defaults[ $key ];
		}

		foreach ( array( 'post_mid_threshold', 'post_old_threshold' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? (int) $input[ $key ] : 0;
			$out[ $key ] = in_array( $value, $valid_thr, true ) ? $value : $defaults[ $key ];
		}

		// Enforce ordering: old threshold must be >= mid threshold.
		if ( $out['post_old_threshold'] < $out['post_mid_threshold'] ) {
			$out['post_old_threshold'] = $defaults['post_old_threshold'];
			add_settings_error(
				TEAM51_CACHE_CONTROL_OPTION,
				'threshold_order',
				__( 'The "1 year" threshold must be greater than the "1 week" threshold. Reset that field to its default.', 'team51-cache-control' )
			);
		}

		// And the old TTL should be >= mid TTL — caching old content less
		// aggressively than mid content makes no sense.
		if ( $out['post_old_seconds'] < $out['post_mid_seconds'] ) {
			$out['post_old_seconds'] = $defaults['post_old_seconds'];
			add_settings_error(
				TEAM51_CACHE_CONTROL_OPTION,
				'ttl_order',
				__( 'Older posts should be cached at least as long as newer ones. Reset that field to its default.', 'team51-cache-control' )
			);
		}

		// Recent tier should not exceed mid tier TTL (older content >= fresher).
		if ( $out['post_recent_seconds'] > $out['post_mid_seconds'] ) {
			$out['post_recent_seconds'] = $defaults['post_recent_seconds'];
			add_settings_error(
				TEAM51_CACHE_CONTROL_OPTION,
				'recent_mid_ttl',
				__( 'Recent posts should not be cached longer than the “older posts (mid)” tier. The recent-posts field was reset to its default.', 'team51-cache-control' )
			);
		}

		return $out;
	}

	/**
	 * Reset settings to defaults. Wired to admin-post.php.
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'team51-cache-control' ) );
		}
		check_admin_referer( 'team51_cache_control_reset' );

		update_option( TEAM51_CACHE_CONTROL_OPTION, \team51_cache_control_defaults() );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'reset' => '1' ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cache Control', 'team51-cache-control' ); ?></h1>

			<?php if ( ! empty( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings reset to defaults.', 'team51-cache-control' ); ?></p></div>
			<?php endif; ?>

			<?php settings_errors( TEAM51_CACHE_CONTROL_OPTION ); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Reset all cache settings to defaults?', 'team51-cache-control' ) ); ?>');">
				<input type="hidden" name="action" value="team51_cache_control_reset" />
				<?php wp_nonce_field( 'team51_cache_control_reset' ); ?>
				<?php submit_button( __( 'Reset to defaults', 'team51-cache-control' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}

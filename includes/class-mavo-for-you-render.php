<?php
/**
 * Everything the cached page contains: an empty placeholder, a small script,
 * and a block of static configuration.
 *
 * Nothing here varies between anonymous visitors — that is the whole point.
 * The personalized part arrives later, from the REST endpoint, and is written
 * into the placeholder by the browser.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Render {

	/** Guards against a theme or shortcode running the hook twice. */
	private static bool $rendered = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'template_redirect', [ __CLASS__, 'protect_debug_response' ] );
		add_filter( 'cache_enabler_bypass_cache', [ __CLASS__, 'bypass_cache' ] );

		if ( self::theme_hook_available() ) {
			// GeneratePress fires this inside the <article>, after
			// .entry-content and before the comments template.
			add_action( 'generate_after_content', [ __CLASS__, 'render_placeholder' ], 20 );
		} else {
			add_filter( 'the_content', [ __CLASS__, 'append_placeholder' ], 20 );
		}
	}

	/**
	 * A debug page view carries a REST nonce in its markup, so it must never
	 * reach a shared cache.
	 *
	 * Two mechanisms, the same pair mavo-contact uses and for the same reason:
	 * DONOTCACHEPAGE is a widely-followed convention rather than an API, while
	 * cache_enabler_bypass_cache is the documented filter of the cache that
	 * actually runs on this site. The comment here used to say Swift
	 * Performance, which has since been replaced by Cache Enabler — a good
	 * illustration of why resting on a convention is not enough.
	 *
	 * Three things would each have to fail before a nonce leaked into a shared
	 * cache — the request carries a query string, the viewer is logged in, and
	 * both flags below. That is the point: none of them is this plugin's to
	 * guarantee.
	 */
	public static function protect_debug_response(): void {
		if ( ! self::debug_requested() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}

	/**
	 * Cache Enabler's own bypass decision.
	 *
	 * Deliberately tests the query argument rather than calling
	 * debug_requested(): that asks current_user_can(), and this filter can run
	 * before the current user is established. Refusing to cache a request that
	 * merely asks for debug output costs nothing — a visitor without the
	 * capability gets an ordinary page, just an uncached one.
	 *
	 * @param mixed $bypass Whether the cache is already being bypassed.
	 */
	public static function bypass_cache( $bypass ): bool {
		if ( $bypass ) {
			return true;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a cache decision, not a state change.
		return ! empty( $_GET[ MFY_Config::DEBUG_QUERY_ARG ] );
	}

	private static function theme_hook_available(): bool {
		return (bool) apply_filters( 'mavo_for_you_use_theme_hook', function_exists( 'generate_get_defaults' ) );
	}

	// -------------------------------------------------------------------------
	// Eligibility
	// -------------------------------------------------------------------------

	/** Is this request a singular content page worth tracking at all? */
	public static function is_trackable_page(): bool {
		if ( is_admin() || is_feed() || is_search() || is_404() || is_front_page() ) {
			return false;
		}
		if ( ! is_singular( MFY_Config::trackable_post_types() ) ) {
			return false;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		// Utility pages and the mavo_for_you_track_post filter, decided in one
		// place so the endpoint applies exactly the same rule.
		return MFY_Data::should_track_post( $post->ID );
	}

	/** Should the block itself be offered on this request? */
	public static function should_show_block(): bool {
		if ( ! self::is_trackable_page() ) {
			return false;
		}

		$post = get_queried_object();
		$lang = self::current_lang();

		if ( '' === $lang || ! MFY_Config::is_enabled_for_lang( $lang ) ) {
			return false;
		}

		return (bool) apply_filters( 'mavo_for_you_show_block', true, $post->ID );
	}

	/** Current language slug, or '' when Polylang cannot say. */
	private static function current_lang(): string {
		$post = get_queried_object();

		if ( $post instanceof WP_Post ) {
			$lang = MFY_Data::post_lang( $post->ID );
			if ( '' !== $lang ) {
				return $lang;
			}
		}

		if ( function_exists( 'pll_current_language' ) ) {
			return (string) ( pll_current_language( 'slug' ) ?: '' );
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Placeholder
	// -------------------------------------------------------------------------

	public static function render_placeholder(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered markup, escaped below.
		echo self::placeholder_html();
	}

	/**
	 * the_content fallback for non-GeneratePress themes. Guarded so an excerpt
	 * pass, a secondary loop or a shortcode re-running the filter cannot insert
	 * a second placeholder.
	 */
	public static function append_placeholder( string $content ): string {
		if ( self::$rendered || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $content . self::placeholder_html();
	}

	private static function placeholder_html(): string {
		if ( self::$rendered || ! self::should_show_block() ) {
			return '';
		}

		// The post places the block itself with [geo_related]. One block per
		// page: the shortcode's position wins, and this hook stands down —
		// whether or not the shortcode found anything worth showing, since a
		// second block after the content would be exactly the duplication this
		// replaces.
		if ( MFY_Shortcode::post_has_shortcode( (int) get_queried_object_id() ) ) {
			return '';
		}

		self::$rendered = true;

		$post_id = (int) get_queried_object_id();
		$html    = sprintf(
			'<div id="mavo-for-you" class="mavo-for-you-placeholder" data-current-post-id="%d"></div>',
			$post_id
		);

		return (string) apply_filters( 'mavo_for_you_placeholder_html', $html, $post_id );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue(): void {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		$show_block = self::should_show_block();
		$is_search  = is_search();
		$mark_read  = MFY_Config::mark_read_enabled() && MFY_Config::mark_read_everywhere();

		// The suggestions page builds itself out of the local profile this
		// script owns, so it is a reason to load even though it shows no block
		// of its own and marks nothing until its rows arrive.
		$is_page = MFY_Page::is_page();

		// Four reasons to load: the block, a search page (where the script
		// only records the search term), the suggestions page, and any other
		// page where links to already-read articles should be marked.
		if ( ! $show_block && ! $is_search && ! $is_page && ! $mark_read ) {
			return;
		}

		$lang = $show_block
			? self::current_lang()
			: ( function_exists( 'pll_current_language' ) ? (string) ( pll_current_language( 'slug' ) ?: '' ) : '' );

		if ( '' === $lang || ! MFY_Config::is_enabled_for_lang( $lang ) ) {
			return;
		}

		// The stylesheet carries the read-marks too, so it travels with the
		// script rather than only with the block.
		wp_enqueue_style(
			'mavo-for-you',
			MFY_PLUGIN_URL . 'assets/css/mavo-for-you.css',
			[],
			self::asset_version( 'assets/css/mavo-for-you.css' )
		);

		wp_enqueue_script(
			'mavo-for-you',
			MFY_PLUGIN_URL . 'assets/js/mavo-for-you.js',
			[],
			self::asset_version( 'assets/js/mavo-for-you.js' ),
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		wp_add_inline_script(
			'mavo-for-you',
			'window.mavoForYouConfig = ' . wp_json_encode( self::config( $lang, $show_block ) ) . ';',
			'before'
		);
	}

	/**
	 * The configuration baked into the cached page.
	 *
	 * Static per (language, post) and identical for every anonymous visitor —
	 * no personalization may leak in here, or the page stops being cacheable.
	 */
	private static function config( string $lang, bool $show_block ): array {
		$debug = self::debug_requested();

		// 'content' tracks and asks for recommendations; 'search' only records
		// the search term; 'mark' does neither and exists solely to mark links
		// to what has already been read.
		$mode = $show_block ? 'content' : ( is_search() ? 'search' : 'mark' );

		$config = [
			'endpoint'           => MFY_Rest::url(),
			'postId'             => $show_block ? (int) get_queried_object_id() : 0,
			'maxLimit'           => MFY_Config::shortcode_max_limit(),
			'lang'               => $lang,
			'mode'               => $mode,
			'markRead'           => MFY_Config::mark_read_enabled(),
			'readSelectors'      => MFY_Config::mark_read_selectors(),
			'showBlock'          => $show_block,
			'storageKey'         => MFY_Config::STORAGE_KEY,
			'schemaVersion'      => MFY_Config::PROFILE_SCHEMA_VERSION,
			'historyTtl'         => MFY_Config::history_ttl(),
			'maxViews'           => MFY_Config::max_views(),
			'maxSearches'        => MFY_Config::max_searches(),
			'maxSearchLength'    => MFY_Config::max_search_length(),
			'minDuration'        => MFY_Config::min_duration(),
			'minScroll'          => MFY_Config::min_scroll(),
			'maxDuration'        => MFY_Config::max_duration(),
			'minMeaningfulViews' => MFY_Config::min_meaningful_views(),
			'saveInterval'       => 15,
			'debug'              => $debug,
			'labels'             => MFY_Config::labels( $lang ),
		];

		if ( $debug ) {
			// Cookie auth over REST needs the nonce; only an administrator who
			// explicitly asked for debug output ever gets one, and such a
			// request is not a cacheable page view.
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		return $config;
	}

	public static function debug_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle.
		return ! empty( $_GET[ MFY_Config::DEBUG_QUERY_ARG ] ) && current_user_can( 'manage_options' );
	}

	private static function asset_version( string $relative ): string {
		$path = MFY_PLUGIN_DIR . $relative;

		return file_exists( $path ) ? (string) filemtime( $path ) : MFY_VERSION;
	}
}

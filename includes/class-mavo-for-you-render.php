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
	 * reach a shared cache. Logged-in visitors normally bypass Swift and
	 * Cloudflare anyway; this makes it explicit rather than assumed.
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

		return (bool) apply_filters( 'mavo_for_you_track_post', true, $post->ID );
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
		$show_block = self::should_show_block();
		$is_search  = is_search();

		// The script also runs on search result pages, where it does nothing
		// but record the search term for later.
		if ( ! $show_block && ! $is_search ) {
			return;
		}

		$lang = $is_search && ! $show_block
			? ( function_exists( 'pll_current_language' ) ? (string) ( pll_current_language( 'slug' ) ?: '' ) : '' )
			: self::current_lang();

		if ( '' === $lang || ! MFY_Config::is_enabled_for_lang( $lang ) ) {
			return;
		}

		if ( $show_block ) {
			wp_enqueue_style(
				'mavo-for-you',
				MFY_PLUGIN_URL . 'assets/css/mavo-for-you.css',
				[],
				self::asset_version( 'assets/css/mavo-for-you.css' )
			);
		}

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

		$config = [
			'endpoint'           => MFY_Rest::url(),
			'postId'             => $show_block ? (int) get_queried_object_id() : 0,
			'lang'               => $lang,
			'mode'               => $show_block ? 'content' : 'search',
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

<?php
/**
 * The standalone suggestions page — /pour-vous/ — and the shortcode that
 * marks where it goes.
 *
 * The page itself is an ordinary WordPress page, created by hand, containing
 * one shortcode. That is the whole of its server-side existence: the shortcode
 * emits a placeholder and nothing else, so the page is as cacheable as any
 * other, and every row arrives afterwards from the REST endpoint. Exactly the
 * arrangement the "Pour vous" block uses, for exactly the same reason.
 *
 * This class also owns the question "does that page exist in this language?",
 * because the block needs an answer before it can offer a link to it, and a
 * link to a page nobody has created yet is worse than no link at all.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Page {

	const TAG = 'mavo_for_you_page';

	/** Per-request memo of resolved page IDs, keyed by language slug. */
	private static array $page_ids = [];

	private static bool $rendered = false;

	public static function init(): void {
		add_shortcode( self::TAG, [ __CLASS__, 'render_shortcode' ] );

		// After MFY_Render::enqueue(), whose script this one depends on.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ], 20 );

		// The suggestions page is not reading material: visiting it must not
		// change what it suggests.
		add_filter( 'mavo_for_you_track_post', [ __CLASS__, 'never_track_self' ], 10, 2 );

		// A new page, or a slug change, has to be findable without waiting out
		// a transient.
		add_action( 'save_post_page', [ __CLASS__, 'forget_page_ids' ] );
		add_action( 'deleted_post', [ __CLASS__, 'forget_page_ids' ] );
	}

	// -------------------------------------------------------------------------
	// Where the page is
	// -------------------------------------------------------------------------

	/**
	 * The page's post ID in a language, or 0.
	 *
	 * Resolved from the configured slug rather than stored in an option: the
	 * page is created by hand, and asking an editor to then come and register
	 * it in a settings screen is one step too many for something a slug lookup
	 * answers. The result is cached, and the lookup itself is one indexed
	 * query on a miss.
	 */
	public static function page_id( string $lang ): int {
		if ( isset( self::$page_ids[ $lang ] ) ) {
			return self::$page_ids[ $lang ];
		}

		$filtered = (int) apply_filters( 'mavo_for_you_page_id', 0, $lang );

		if ( $filtered > 0 ) {
			return self::$page_ids[ $lang ] = self::validate( $filtered, $lang );
		}

		$key    = MFY_Cache::key( 'pageid', [ $lang ] );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return self::$page_ids[ $lang ] = (int) $cached;
		}

		$slugs = MFY_Config::page_slugs();
		$slug  = (string) ( $slugs[ $lang ] ?? '' );
		$found = 0;

		if ( '' !== $slug ) {
			$page  = get_page_by_path( $slug, OBJECT, 'page' );
			$found = $page instanceof WP_Post ? self::validate( $page->ID, $lang ) : 0;

			// Polylang gives each translation its own slug, but a site may
			// have kept one: ask Polylang for this language's version too.
			if ( ! $found && $page instanceof WP_Post && function_exists( 'pll_get_post' ) ) {
				$translated = (int) pll_get_post( $page->ID, $lang );
				$found      = $translated ? self::validate( $translated, $lang ) : 0;
			}
		}

		set_transient( $key, $found, DAY_IN_SECONDS );

		return self::$page_ids[ $lang ] = $found;
	}

	/** A published page, in the language it claims to be in. */
	private static function validate( int $post_id, string $lang ): int {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
			return 0;
		}

		return MFY_Data::post_lang( $post_id ) === $lang ? $post_id : 0;
	}

	public static function forget_page_ids(): void {
		self::$page_ids = [];

		foreach ( MFY_Config::site_langs() as $lang ) {
			delete_transient( MFY_Cache::key( 'pageid', [ $lang ] ) );
		}
	}

	/** Is there a suggestions page to send this language's readers to? */
	public static function available( string $lang ): bool {
		return in_array( $lang, MFY_Config::page_langs(), true )
			&& MFY_Config::is_enabled_for_lang( $lang )
			&& self::page_id( $lang ) > 0;
	}

	public static function url( string $lang ): string {
		$post_id = self::page_id( $lang );

		return $post_id ? (string) get_permalink( $post_id ) : '';
	}

	/** Is this request the suggestions page? */
	public static function is_page(): bool {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		// The shortcode is the authority — a page carrying it *is* the
		// suggestions page, whatever its slug. The registered ID is the
		// fallback for a page that builds its content some other way.
		if ( has_shortcode( (string) $post->post_content, self::TAG ) ) {
			return true;
		}

		$lang = MFY_Data::post_lang( $post->ID );

		return '' !== $lang && self::page_id( $lang ) === $post->ID;
	}

	/**
	 * Is this post the suggestions page, as far as the endpoint is concerned?
	 *
	 * Looser than page_id(), deliberately. That method answers "where do I
	 * send a reader?", which needs one canonical page per language. This one
	 * answers "may this page ask for rows?", and a page carrying the shortcode
	 * is asking in good faith whatever slug it was given — an editor who
	 * creates /mes-suggestions/ instead should get a working page, not a
	 * silent failure with no way to see why.
	 */
	public static function is_suggestions_page( int $post_id, string $lang ): bool {
		if ( self::validate( $post_id, $lang ) !== $post_id ) {
			return false;
		}

		if ( self::page_id( $lang ) === $post_id ) {
			return true;
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, self::TAG );
	}

	/** Never let the suggestions page enter the profile it is built from. */
	public static function never_track_self( $should_track, $post_id ) {
		$lang = MFY_Data::post_lang( (int) $post_id );

		if ( '' !== $lang && self::page_id( $lang ) === (int) $post_id ) {
			return false;
		}

		return $should_track;
	}

	// -------------------------------------------------------------------------
	// The placeholder
	// -------------------------------------------------------------------------

	/**
	 * Everything this shortcode puts in the cached page: an empty container
	 * and a line saying the rows are on their way.
	 *
	 * No suggestion, no post ID, nothing that could differ between two
	 * visitors — so Swift and Cloudflare may cache this page exactly as they
	 * cache an article. The <noscript> rule is the graceful-degradation half:
	 * with scripting off the rows can never arrive, so the loading line hides
	 * itself and says what is actually true instead.
	 */
	public static function render_shortcode( $atts = [] ): string {
		$lang = self::current_lang();

		if ( '' === $lang || self::$rendered ) {
			return '';
		}

		self::$rendered = true;

		$labels = MFY_Config::page_labels( $lang );

		$html = sprintf(
			'<div id="mavo-for-you-page" class="mfy-page-placeholder" data-lang="%1$s" data-page-id="%2$d">'
				. '<p class="mfy-page__loading">%3$s</p>'
				. '<noscript><style>.mfy-page__loading{display:none}</style><p class="mfy-page__empty">%4$s</p></noscript>'
				. '</div>',
			esc_attr( $lang ),
			(int) get_the_ID(),
			esc_html( (string) ( $labels['loading'] ?? '' ) ),
			esc_html( (string) ( $labels['empty'] ?? '' ) )
		);

		return (string) apply_filters( 'mavo_for_you_page_placeholder_html', $html, $lang );
	}

	private static function current_lang(): string {
		// get_the_ID() outside the loop — during wp_enqueue_scripts, say —
		// answers false, so the queried object is the fallback.
		$post_id = (int) ( get_the_ID() ?: get_queried_object_id() );

		if ( $post_id ) {
			$lang = MFY_Data::post_lang( $post_id );
			if ( '' !== $lang ) {
				return $lang;
			}
		}

		return function_exists( 'pll_current_language' ) ? (string) ( pll_current_language( 'slug' ) ?: '' ) : '';
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * The page's own script and stylesheet, on top of the block's.
	 *
	 * mavo-for-you.js is the dependency and not a duplicate: the local profile,
	 * its expiry rules and the already-read marking all live there, and the
	 * page reads them through the small API that script exposes. MFY_Render
	 * loads it here because MFY_Page::is_page() is one of its reasons to load.
	 */
	public static function enqueue(): void {
		if ( is_admin() || ! self::is_page() ) {
			return;
		}

		$lang = self::current_lang();

		if ( '' === $lang || ! MFY_Config::is_enabled_for_lang( $lang ) ) {
			return;
		}

		// No block script means no profile access; rendering rows without it
		// is not possible, and half a page is worse than the loading line.
		if ( ! wp_script_is( 'mavo-for-you', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'mavo-for-you-page',
			MFY_PLUGIN_URL . 'assets/css/mavo-for-you-page.css',
			[ 'mavo-for-you' ],
			self::asset_version( 'assets/css/mavo-for-you-page.css' )
		);

		wp_enqueue_script(
			'mavo-for-you-page',
			MFY_PLUGIN_URL . 'assets/js/mavo-for-you-page.js',
			[ 'mavo-for-you' ],
			self::asset_version( 'assets/js/mavo-for-you-page.js' ),
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);

		wp_add_inline_script(
			'mavo-for-you-page',
			'window.mavoForYouPageConfig = ' . wp_json_encode( self::config( $lang ) ) . ';',
			'before'
		);
	}

	/** Static per language, like everything else the cached page carries. */
	private static function config( string $lang ): array {
		return [
			'endpoint' => MFY_Rest::page_url(),
			'lang'     => $lang,
			'pageId'   => (int) get_queried_object_id(),
			'labels'   => MFY_Config::page_labels( $lang ),
			'rowMin'   => MFY_Config::page_row_min(),
		];
	}

	private static function asset_version( string $relative ): string {
		$path = MFY_PLUGIN_DIR . $relative;

		return file_exists( $path ) ? (string) filemtime( $path ) : MFY_VERSION;
	}
}

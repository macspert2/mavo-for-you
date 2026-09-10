<?php
/**
 * POST /wp-json/mavo/v1/for-you
 *
 * Anonymous, uncacheable, and suspicious of everything the client sends: the
 * payload is browser-held data, so IDs, languages, durations and scroll values
 * are all re-derived or clamped here before any of it reaches a query.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Rest {

	const REST_NAMESPACE = 'mavo/v1';
	const ROUTE          = '/for-you';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ __CLASS__, 'handle' ],
				// Read-only recommendations for anonymous visitors: no nonce to
				// check. The defence here is strict validation and bounded work,
				// not authentication.
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function url(): string {
		return rest_url( self::REST_NAMESPACE . self::ROUTE );
	}

	public static function handle( WP_REST_Request $request ) {
		$debug_mode = current_user_can( 'manage_options' ) && $request->get_param( 'debug' );

		$current_post_id = absint( $request->get_param( 'current_post_id' ) );

		if ( ! $current_post_id || ! MFY_Data::is_valid_post( $current_post_id, MFY_Config::trackable_post_types() ) ) {
			return self::respond( self::empty_response( '', 'invalid current_post_id' ), $debug_mode );
		}

		// The client's own idea of the language is ignored entirely.
		$lang = MFY_Data::post_lang( $current_post_id );

		if ( '' === $lang ) {
			// Polylang missing or the post has no language: showing something
			// would risk mixing languages, so show nothing.
			return self::respond( self::empty_response( '', 'language could not be determined' ), $debug_mode );
		}

		if ( ! MFY_Config::is_enabled_for_lang( $lang ) ) {
			return self::respond( self::empty_response( $lang, 'disabled for this language' ), $debug_mode );
		}

		if ( ! MFY_Data::integration_available() ) {
			self::log_integration_missing();
			return self::respond( self::empty_response( $lang, 'travel-finder filter data unavailable' ), $debug_mode );
		}

		$views    = self::sanitize_views( (array) $request->get_param( 'views' ), $lang );
		$searches = self::sanitize_searches( (array) $request->get_param( 'searches' ) );
		$referral = self::sanitize_referral( $request->get_param( 'referral' ) );

		if ( self::count_meaningful( $views ) < MFY_Config::min_meaningful_views() ) {
			return self::respond( self::empty_response( $lang, 'not enough meaningful views' ), $debug_mode );
		}

		$ranked = MFY_Scorer::rank( $current_post_id, $lang, $views, $searches, $referral );

		$response = [
			'show'            => ! empty( $ranked['recommendations'] ),
			'lang'            => $lang,
			'recommendations' => $ranked['recommendations'],
			'recently_viewed' => self::recently_viewed( $views, $current_post_id, $lang ),
		];

		if ( $debug_mode ) {
			$response['debug'] = $ranked['debug'];
		}

		return self::respond( $response, $debug_mode );
	}

	// -------------------------------------------------------------------------
	// Input validation
	// -------------------------------------------------------------------------

	/**
	 * Views the server is willing to believe in: real published posts of the
	 * right type, in the current language, with clamped engagement numbers.
	 */
	private static function sanitize_views( array $views, string $lang ): array {
		$views = array_slice( array_values( $views ), 0, MFY_Config::max_views() );
		$types = MFY_Config::trackable_post_types();
		$cap   = MFY_Config::max_duration();
		$now   = time();
		$seen  = [];
		$out   = [];

		foreach ( $views as $view ) {
			if ( ! is_array( $view ) ) {
				continue;
			}

			$post_id = absint( $view['post_id'] ?? 0 );

			if ( ! $post_id || isset( $seen[ $post_id ] ) ) {
				continue;
			}
			if ( ! MFY_Data::is_valid_post( $post_id, $types ) ) {
				continue;
			}
			// Same-language only, enforced here rather than trusted from the
			// payload's own "lang" field.
			if ( MFY_Data::post_lang( $post_id ) !== $lang ) {
				continue;
			}

			$seen[ $post_id ] = true;

			// intval, not absint: a negative duration is a broken client, and
			// flipping its sign would turn nonsense into a plausible-looking
			// engagement figure. It floors at 0 instead.
			$out[] = [
				'post_id'          => $post_id,
				'duration_seconds' => max( 0, min( $cap, (int) ( $view['duration_seconds'] ?? 0 ) ) ),
				'max_scroll_pct'   => max( 0, min( 100, (int) ( $view['max_scroll_pct'] ?? 0 ) ) ),
				'last_seen'        => min( $now, absint( $view['last_seen'] ?? 0 ) ?: $now ),
			];
		}

		return $out;
	}

	private static function sanitize_searches( array $searches ): array {
		$searches = array_slice( array_values( $searches ), 0, MFY_Config::max_searches() );
		$max_len  = MFY_Config::max_search_length();
		$out      = [];

		foreach ( $searches as $search ) {
			if ( ! is_array( $search ) ) {
				continue;
			}

			$query = sanitize_text_field( (string) ( $search['query'] ?? '' ) );
			$query = trim( preg_replace( '/\s+/u', ' ', $query ) );

			if ( '' === $query || mb_strlen( $query ) > $max_len ) {
				continue;
			}

			$out[] = [
				'query'     => $query,
				'source'    => sanitize_key( (string) ( $search['source'] ?? 'site' ) ),
				'timestamp' => absint( $search['timestamp'] ?? 0 ),
			];
		}

		return $out;
	}

	private static function sanitize_referral( $referral ): ?array {
		if ( ! is_array( $referral ) ) {
			return null;
		}

		$source = sanitize_text_field( (string) ( $referral['source'] ?? '' ) );
		if ( '' === $source ) {
			return null;
		}

		$query = isset( $referral['query'] ) && is_string( $referral['query'] )
			? sanitize_text_field( $referral['query'] )
			: '';
		if ( mb_strlen( $query ) > MFY_Config::max_search_length() ) {
			$query = '';
		}

		return [
			'source'          => mb_substr( $source, 0, 100 ),
			'query'           => '' === $query ? null : $query,
			'landing_post_id' => absint( $referral['landing_post_id'] ?? 0 ),
			'timestamp'       => absint( $referral['timestamp'] ?? 0 ),
		];
	}

	/** Views engaged enough to justify personalizing at all. */
	private static function count_meaningful( array $views ): int {
		$min_duration = MFY_Config::min_duration();
		$min_scroll   = MFY_Config::min_scroll();
		$count        = 0;

		foreach ( $views as $view ) {
			if ( $view['duration_seconds'] >= $min_duration || $view['max_scroll_pct'] >= $min_scroll ) {
				++$count;
			}
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Output
	// -------------------------------------------------------------------------

	/**
	 * Most recent first, current page excluded, already language-filtered.
	 *
	 * With one deliberate exception: a hub that has been read this visit is
	 * guaranteed a place in the list even when three newer articles have
	 * pushed it out. An already-read hub is never re-recommended — it is not
	 * news — but it is the page a reader is most likely to want to get back
	 * to, and dropping it off the bottom of a strict recency list makes that
	 * return harder than it needs to be.
	 */
	private static function recently_viewed( array $views, int $current_post_id, string $lang ): array {
		usort( $views, static fn( $a, $b ) => $b['last_seen'] <=> $a['last_seen'] );

		$limit     = MFY_Config::num_recently_viewed();
		$eligible  = [];
		$first_hub = null;

		foreach ( $views as $view ) {
			if ( $view['post_id'] === $current_post_id ) {
				continue;
			}

			$eligible[] = $view['post_id'];

			if ( null === $first_hub && MFY_Hubs::is_hub( $view['post_id'] ) ) {
				$first_hub = $view['post_id'];
			}
		}

		$shown = array_slice( $eligible, 0, $limit );

		// The hub exists, was read, and recency alone would have hidden it:
		// it takes the last slot rather than being lost.
		if ( $first_hub && $shown && ! in_array( $first_hub, $shown, true ) ) {
			array_splice( $shown, $limit - 1, 1, [ $first_hub ] );
		}

		$out = [];
		foreach ( $shown as $post_id ) {
			$item = MFY_Data::format_item( $post_id, false );
			if ( ! $item ) {
				continue;
			}

			$hub_type = MFY_Hubs::hub_type( $post_id );
			if ( $hub_type ) {
				$item['hub'] = [
					'type'  => $hub_type,
					'label' => MFY_Hubs::label( $hub_type, $lang ),
				];
			}

			$out[] = $item;
		}

		return $out;
	}

	private static function empty_response( string $lang, string $reason ): array {
		return [
			'show'            => false,
			'lang'            => $lang,
			'recommendations' => [],
			'recently_viewed' => [],
			'reason'          => $reason,
		];
	}

	/**
	 * Personalized output must never land in a shared cache — not Swift, not
	 * Cloudflare, not the browser's.
	 */
	private static function respond( array $data, bool $debug_mode ): WP_REST_Response {
		if ( ! $debug_mode ) {
			unset( $data['debug'] );
		}

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'private, no-store, no-cache, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Accel-Expires', '0' );

		return $response;
	}

	/**
	 * A missing tvf integration is a site-configuration problem an admin needs
	 * to know about, so it is logged — once per request, and with no visitor
	 * data in it.
	 */
	private static function log_integration_missing(): void {
		static $logged = false;

		if ( $logged || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$logged = true;
		error_log( '[mavo-for-you] Travel Finder filter data is unavailable (TVF_Store / tvf_get_registry missing); no recommendations can be produced.' );
	}
}

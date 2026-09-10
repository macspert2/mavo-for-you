<?php
/**
 * Every tunable number the plugin uses, in one place.
 *
 * Nothing else in the plugin may hard-code a threshold, weight or limit —
 * scoring is meant to be tuned from here (or from the filters below) without
 * hunting through the classes.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Config {

	/** localStorage key holding the visitor's short-term profile. */
	const STORAGE_KEY = 'mavo_for_you_v0';

	/** Bump when the stored schema changes; older payloads are discarded. */
	const PROFILE_SCHEMA_VERSION = 1;

	/** Option holding the per-language on/off toggles. */
	const OPTION_ENABLED_LANGS = 'mavo_for_you_enabled_langs';

	/** Query arg that turns on admin-only debug output. */
	const DEBUG_QUERY_ARG = 'mavo_for_you_debug';

	/**
	 * The tvf filter categories that carry the "où partir" signal.
	 *
	 * Intérêt, géographie and âge des enfants describe what a reader is drawn
	 * to. Saison / durée / budget describe trip logistics and are scored so
	 * broadly across the catalogue that including them would flatten the
	 * ranking rather than sharpen it.
	 */
	public static function signal_categories(): array {
		return (array) apply_filters(
			'mavo_for_you_signal_categories',
			[ 'interet', 'geographie', 'age_enfants' ]
		);
	}

	/** Post types whose views enter the local profile. */
	public static function trackable_post_types(): array {
		return (array) apply_filters( 'mavo_for_you_trackable_post_types', [ 'post', 'page' ] );
	}

	/**
	 * Post types that may be recommended.
	 *
	 * Posts only: the tvf filter table is populated for posts, so a page could
	 * never satisfy the "one filter scored 2" eligibility rule anyway.
	 */
	public static function candidate_post_types(): array {
		return (array) apply_filters( 'mavo_for_you_candidate_post_types', [ 'post' ] );
	}

	/** Seconds a stored profile survives after the last interaction. */
	public static function history_ttl(): int {
		return (int) apply_filters( 'mavo_for_you_history_ttl', DAY_IN_SECONDS );
	}

	/** Hard cap on stored views. Also the cap the REST endpoint enforces. */
	public static function max_views(): int {
		return (int) apply_filters( 'mavo_for_you_max_views', 20 );
	}

	/** Hard cap on stored on-site searches. */
	public static function max_searches(): int {
		return (int) apply_filters( 'mavo_for_you_max_searches', 5 );
	}

	/** Max length of a stored/accepted search string. */
	public static function max_search_length(): int {
		return (int) apply_filters( 'mavo_for_you_max_search_length', 200 );
	}

	/** Visible seconds after which a view counts as meaningful. */
	public static function min_duration(): int {
		return (int) apply_filters( 'mavo_for_you_min_duration', 8 );
	}

	/** Scroll percentage after which a view counts as meaningful. */
	public static function min_scroll(): int {
		return (int) apply_filters( 'mavo_for_you_min_scroll', 25 );
	}

	/** Per-view reading-duration cap, guarding against abandoned tabs. */
	public static function max_duration(): int {
		return (int) apply_filters( 'mavo_for_you_max_duration', 600 );
	}

	/** Meaningful views required before the block may appear. */
	public static function min_meaningful_views(): int {
		return (int) apply_filters( 'mavo_for_you_min_meaningful_views', 2 );
	}

	/** Recommendations returned. */
	public static function num_recommendations(): int {
		return (int) apply_filters( 'mavo_for_you_num_recommendations', 3 );
	}

	/** Recently-viewed entries returned. */
	public static function num_recently_viewed(): int {
		return (int) apply_filters( 'mavo_for_you_num_recently_viewed', 3 );
	}

	/**
	 * Minimum total score a candidate must reach to be shown.
	 *
	 * One strong shared filter on the current page, read attentively, is worth
	 * 8 × 1.00 recency × ~1.0 engagement = 8. The threshold sits just under
	 * that: a single solid overlap qualifies, a stray weak overlap does not.
	 * Tune once real score distributions are visible in debug mode.
	 */
	public static function min_score( array $context = [] ): float {
		return (float) apply_filters( 'mavo_for_you_min_score', 7.5, $context );
	}

	/**
	 * Recency weight by position in the viewed list, most recent (the current
	 * page) first. Positions past the end of the list use the last value.
	 */
	public static function recency_weights(): array {
		return (array) apply_filters( 'mavo_for_you_recency_weights', [ 1.00, 0.80, 0.60, 0.45, 0.30 ] );
	}

	/** [ min_seconds => multiplier ], descending by threshold. */
	public static function duration_multipliers(): array {
		return (array) apply_filters( 'mavo_for_you_duration_multipliers', [
			180 => 1.25,
			90  => 1.15,
			30  => 1.00,
			8   => 0.60,
			0   => 0.25,
		] );
	}

	/** [ min_percent => multiplier ], descending by threshold. */
	public static function scroll_multipliers(): array {
		return (array) apply_filters( 'mavo_for_you_scroll_multipliers', [
			90 => 1.25,
			75 => 1.15,
			50 => 1.00,
			25 => 0.75,
			0  => 0.50,
		] );
	}

	/** Clamp applied to the combined engagement multiplier. */
	public static function engagement_bounds(): array {
		return (array) apply_filters( 'mavo_for_you_engagement_bounds', [ 0.25, 1.25 ] );
	}

	/** Points for a 2 ↔ 2 filter match, before recency/engagement weighting. */
	public static function points_strong_match(): float {
		return (float) apply_filters( 'mavo_for_you_points_strong_match', 8.0 );
	}

	/** Points for candidate=1 against a session filter of 2. */
	public static function points_weak_match(): float {
		return (float) apply_filters( 'mavo_for_you_points_weak_match', 2.0 );
	}

	/** Points a candidate scored 2 in a search-mapped filter receives. */
	public static function points_search_strong(): float {
		return (float) apply_filters( 'mavo_for_you_points_search_strong', 2.0 );
	}

	/** Points a candidate scored 1 in a search-mapped filter receives. */
	public static function points_search_weak(): float {
		return (float) apply_filters( 'mavo_for_you_points_search_weak', 1.0 );
	}

	/**
	 * A referral query is a weaker signal than a search the visitor typed on
	 * the site itself, so its search points are scaled down.
	 */
	public static function referral_search_factor(): float {
		return (float) apply_filters( 'mavo_for_you_referral_search_factor', 0.5 );
	}

	/** How many candidates the SQL pool may return before PHP scoring. */
	public static function candidate_pool_size(): int {
		return (int) apply_filters( 'mavo_for_you_candidate_pool_size', 60 );
	}

	/**
	 * Search-token → filter-slug dictionary, per language.
	 *
	 * Deliberately small and literal: V0 does no semantic analysis. Tokens are
	 * matched against the lowercased, accent-folded search string as substrings
	 * of whole words, so "rando" catches "randonnée".
	 */
	public static function search_filter_map( string $lang ): array {
		$map = [
			// Intérêt.
			'plage'      => 'plage_cote',
			'beach'      => 'plage_cote',
			'strand'     => 'plage_cote',
			'cote'       => 'plage_cote',
			'mer'        => 'plage_cote',
			'rando'      => 'nature_rando',
			'hike'       => 'nature_rando',
			'hiking'     => 'nature_rando',
			'wander'     => 'nature_rando',
			'nature'     => 'nature_rando',
			'gastronom'  => 'gastronomie',
			'restaurant' => 'gastronomie',
			'food'       => 'gastronomie',
			'essen'      => 'gastronomie',
			'manger'     => 'gastronomie',
			'culture'    => 'culture_histoire',
			'histoire'   => 'culture_histoire',
			'history'    => 'culture_histoire',
			'museum'     => 'culture_histoire',
			'musee'      => 'culture_histoire',
			'velo'       => 'velo',
			'bike'       => 'velo',
			'cycling'    => 'velo',
			'fahrrad'    => 'velo',
			'voile'      => 'voile',
			'sailing'    => 'voile',
			'segel'      => 'voile',
			'bateau'     => 'voile',
			'campervan'  => 'campervan',
			'camping'    => 'campervan',
			'van'        => 'campervan',
			'wohnmobil'  => 'campervan',
			'ski'        => 'ski',
			'neige'      => 'ski',
			'snow'       => 'ski',
			'schnee'     => 'ski',
			'detente'    => 'detente',
			'relax'      => 'detente',
			'spa'        => 'detente',
			'shopping'   => 'shopping',
			'roadtrip'   => 'roadtrip',
			'road trip'  => 'roadtrip',
			'ville'      => 'citytrip',
			'city'       => 'citytrip',
			'citytrip'   => 'citytrip',
			'city break' => 'citytrip',
			'stadt'      => 'citytrip',
			// Âge des enfants.
			'bebe'       => 'bebes',
			'baby'       => 'bebes',
			'nourrisson' => 'bebes',
			'enfant'     => 'kids',
			'kids'       => 'kids',
			'kinder'     => 'kids',
			'ado'        => 'ados',
			'teen'       => 'ados',
			'teenager'   => 'ados',
			'jugendlich' => 'ados',
			// Géographie.
			'france'     => 'france',
			'frankreich' => 'france',
			'angleterre' => 'angleterre',
			'england'    => 'angleterre',
			'londres'    => 'angleterre',
			'london'     => 'angleterre',
			'mediterran' => 'mediterranee',
			'europe'     => 'europe',
			'europa'     => 'europe',
		];

		return (array) apply_filters( 'mavo_for_you_search_filter_map', $map, $lang );
	}

	/** Languages the block is switched on for. */
	public static function enabled_langs(): array {
		$stored = get_option( self::OPTION_ENABLED_LANGS, null );

		// Never configured: on for every language, so the plugin works as soon
		// as it is activated.
		if ( null === $stored ) {
			return self::site_langs();
		}

		return array_values( array_intersect( self::site_langs(), (array) $stored ) );
	}

	public static function is_enabled_for_lang( string $lang ): bool {
		return in_array( $lang, self::enabled_langs(), true );
	}

	/** Language slugs known to Polylang, with a static fallback. */
	public static function site_langs(): array {
		if ( function_exists( 'pll_languages_list' ) ) {
			$langs = pll_languages_list( [ 'fields' => 'slug' ] );
			if ( ! empty( $langs ) ) {
				return array_values( $langs );
			}
		}

		return [ 'fr', 'en', 'de' ];
	}

	/** UI strings, by language slug. Unknown languages fall back to English. */
	public static function labels( string $lang ): array {
		$labels = [
			'fr' => [
				'heading'  => __( 'Pour vous', 'mavo-for-you' ),
				'subtitle' => __( 'Suggestions basées sur les articles consultés pendant votre visite sur Maman Voyage.', 'mavo-for-you' ),
				'recent'   => __( 'Consultés récemment', 'mavo-for-you' ),
			],
			'en' => [
				'heading'  => __( 'For you', 'mavo-for-you' ),
				'subtitle' => __( "Suggestions based on the articles you've viewed during this visit to Maman Voyage.", 'mavo-for-you' ),
				'recent'   => __( 'Recently viewed', 'mavo-for-you' ),
			],
			'de' => [
				'heading'  => __( 'Für Euch', 'mavo-for-you' ),
				'subtitle' => __( 'Vorschläge auf Basis der Artikel, die Ihr während dieses Besuchs auf Maman Voyage angesehen habt.', 'mavo-for-you' ),
				'recent'   => __( 'Kürzlich angesehen', 'mavo-for-you' ),
			],
		];

		return (array) apply_filters(
			'mavo_for_you_labels',
			$labels[ $lang ] ?? $labels['en'],
			$lang
		);
	}
}

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

	/**
	 * Page slugs that never enter the profile.
	 *
	 * §3 asks for contact/privacy/legal utility pages to be left out "where
	 * practical". WordPress knows its own privacy page and that is handled
	 * separately; the rest is a slug list, because nothing in core marks a page
	 * as a utility page. Deliberately narrow — legal and contact only. An
	 * "about" page is editorial and worth tracking.
	 *
	 * Matched against pages only, never posts, and after a trailing language
	 * or duplicate suffix is stripped ("contact-en", "impressum-2").
	 */
	public static function excluded_page_slugs(): array {
		// The suggestions page joins them by construction rather than by hand:
		// it is built *from* the profile, so letting it into the profile would
		// have a reader's history describe the page that describes it.
		return (array) apply_filters( 'mavo_for_you_excluded_page_slugs', array_merge( array_values( self::page_slugs() ), [
			// FR
			'contact', 'contactez-nous', 'mentions-legales', 'politique-de-confidentialite',
			'confidentialite', 'cgu', 'cgv', 'plan-du-site',
			// EN
			'contact-us', 'privacy', 'privacy-policy', 'legal', 'legal-notice',
			'terms', 'terms-of-service', 'disclaimer', 'sitemap',
			// DE
			'kontakt', 'impressum', 'datenschutz', 'datenschutzerklaerung',
			'agb', 'nutzungsbedingungen', 'haftungsausschluss',
		] ) );
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

	// -------------------------------------------------------------------------
	// Already-read marking
	// -------------------------------------------------------------------------

	/** Mark links to pages this visitor has already opened during the visit. */
	public static function mark_read_enabled(): bool {
		return (bool) apply_filters( 'mavo_for_you_mark_read', true );
	}

	/**
	 * Load the marker on pages that have no block of their own.
	 *
	 * Archive grids and the homepage are where most tiles live, so restricting
	 * the marker to pages that already run the recommendation request would
	 * leave it off exactly where it is most useful. The cost is one small
	 * deferred script on those pages — no REST call, no tracking, no cookies.
	 */
	public static function mark_read_everywhere(): bool {
		return (bool) apply_filters( 'mavo_for_you_mark_read_everywhere', true );
	}

	/**
	 * Where marks may and may not go.
	 *
	 * 'skip' wins over everything: navigation and the footer are wayfinding,
	 * not reading, and every entry under "Consultés récemment" is read by
	 * definition, so marking those is pure noise.
	 */
	public static function mark_read_selectors(): array {
		return (array) apply_filters( 'mavo_for_you_mark_read_selectors', [
			'skip'  => '#mavo-nav, .mavo-nav, .site-footer, .mfy__recent, .mfy__footer, .mfy-page__row--recent',
			'tile'  => '.mv-tile',
			'prose' => '.entry-content',
		] );
	}

	// -------------------------------------------------------------------------
	// [geo_related] — the impersonal block
	// -------------------------------------------------------------------------

	/**
	 * Default slots for the shortcode.
	 *
	 * Six, matching what [geo_related] has always shown, so moving the block
	 * between plugins does not resize a section that is live on many posts.
	 * The personalized block honours whatever the shortcode asked for, so the
	 * two never differ in size; the after-content block keeps its own default
	 * of three.
	 */
	public static function shortcode_limit(): int {
		return (int) apply_filters( 'mavo_for_you_shortcode_limit', 6 );
	}

	/** Ceiling on the shortcode's limit attribute, and on the REST override. */
	public static function shortcode_max_limit(): int {
		return (int) apply_filters( 'mavo_for_you_shortcode_max_limit', 12 );
	}

	/** How long an impersonal block survives, absent an edit. */
	public static function shortcode_cache_ttl(): int {
		return (int) apply_filters( 'mavo_for_you_shortcode_cache_ttl', 12 * HOUR_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Editorial hubs
	// -------------------------------------------------------------------------

	/**
	 * How many of the slots may be given to hubs.
	 *
	 * One. A hub is the single most useful link for someone mid-topic, but two
	 * of three slots pointing at overview pages would turn a recommendation
	 * block into a table of contents. Raise it only if the block grows.
	 */
	public static function max_hub_recommendations(): int {
		return (int) apply_filters( 'mavo_for_you_max_hub_recommendations', 1 );
	}

	/**
	 * How far up the hub chain to look.
	 *
	 * 2: the immediate hub, and its parent. Someone reading Paris articles is
	 * signalling Paris loudly and France faintly; beyond that the relationship
	 * is too abstract to act on.
	 */
	public static function hub_max_depth(): int {
		return (int) apply_filters( 'mavo_for_you_hub_max_depth', 2 );
	}

	/** Weight retained per step up the hub chain. */
	public static function hub_ancestor_decay(): float {
		return (float) apply_filters( 'mavo_for_you_hub_ancestor_decay', 0.5 );
	}

	/**
	 * Points an article earns for being a child of a hub the session is
	 * reading in, per unit of that hub's weight.
	 *
	 * At 10, a child of the hub on the current page clears the minimum score
	 * (7.5) on that relationship alone — which is the point: an editor put it
	 * there by hand. Filter and geography overlap then add to it.
	 */
	public static function hub_child_points(): float {
		return (float) apply_filters( 'mavo_for_you_hub_child_points', 10.0 );
	}

	/** How many of the session's hubs are expanded into their children. */
	public static function hub_child_hubs(): int {
		return (int) apply_filters( 'mavo_for_you_hub_child_hubs', 3 );
	}

	/** Children fetched per expanded hub. */
	public static function hub_child_pool_size(): int {
		return (int) apply_filters( 'mavo_for_you_hub_child_pool_size', 20 );
	}

	/** Points per unit of hub weight — reporting only; see MFY_Hubs::score(). */
	public static function hub_points(): float {
		return (float) apply_filters( 'mavo_for_you_hub_points', 20.0 );
	}

	/**
	 * The reader-facing word for a hub, per language and hub type.
	 *
	 * Deliberately not "hub" in any language — that is the internal name for
	 * the relationship. French editorial usage would call a place page a
	 * *guide* and a topic page a *dossier*; German travel writing has taken
	 * "Guide" as a loanword but *Übersicht* is the plainer word. Both types
	 * default to the same label so the block does not teach a vocabulary;
	 * split them here if that ever becomes useful.
	 */
	public static function hub_labels( string $lang ): array {
		$labels = [
			'fr' => [ 'geo' => __( 'Guide', 'mavo-for-you' ),      'theme' => __( 'Guide', 'mavo-for-you' ) ],
			'en' => [ 'geo' => __( 'Guide', 'mavo-for-you' ),      'theme' => __( 'Guide', 'mavo-for-you' ) ],
			'de' => [ 'geo' => __( 'Übersicht', 'mavo-for-you' ),  'theme' => __( 'Übersicht', 'mavo-for-you' ) ],
		];

		return (array) apply_filters(
			'mavo_for_you_hub_labels',
			$labels[ $lang ] ?? $labels['en'],
			$lang
		);
	}

	// -------------------------------------------------------------------------
	// Geography
	// -------------------------------------------------------------------------

	/**
	 * Place levels that take part in matching, most specific first.
	 *
	 * Continent is deliberately absent: "same continent" is true of most of
	 * the catalogue and would be a signal of nothing.
	 */
	public static function geo_levels(): array {
		return (array) apply_filters( 'mavo_for_you_geo_levels', [ 'city', 'region', 'country' ] );
	}

	/**
	 * Points a candidate earns for sitting in a place the session has been
	 * reading about, multiplied by that place's accumulated session weight.
	 * Only the deepest matching level is awarded.
	 *
	 * Sized against the filter scoring on purpose: one shared strong filter is
	 * 8 points, so a same-city match is worth roughly a filter and a half. It
	 * shifts the ranking without being able to drag in an editorially
	 * irrelevant post on geography alone.
	 */
	public static function geo_points(): array {
		return (array) apply_filters( 'mavo_for_you_geo_points', [
			'city'    => 12.0,
			'region'  => 6.0,
			'country' => 3.0,
		] );
	}

	/**
	 * Share of a level's session weight one place must hold for the session to
	 * count as focused there.
	 *
	 * At 0.6, three London articles are a London session, and so are two
	 * London plus one Barcelona (0.67) — one stray click does not undo the
	 * reader's evident intent. An even three-city split (0.33) is not focused
	 * at any level, and filter scores decide, which is the old behaviour.
	 */
	public static function geo_focus_threshold(): float {
		return (float) apply_filters( 'mavo_for_you_geo_focus_threshold', 0.6 );
	}

	/**
	 * Share of the recommendation slots reserved for the focused place.
	 *
	 * 2 of 3. The remaining slot deliberately goes elsewhere: someone who has
	 * read three London articles wants more London, but not *only* London.
	 */
	public static function geo_reserved_ratio(): float {
		return (float) apply_filters( 'mavo_for_you_geo_reserved_ratio', 2 / 3 );
	}

	/** Slots reserved for the focused place, given a total. */
	public static function geo_reserved_slots( int $total ): int {
		$reserved = (int) floor( $total * self::geo_reserved_ratio() );

		return (int) apply_filters(
			'mavo_for_you_geo_reserved_slots',
			max( 0, min( $total, $reserved ) ),
			$total
		);
	}

	/** How many geography-matched posts may join the candidate pool. */
	public static function geo_pool_size(): int {
		return (int) apply_filters( 'mavo_for_you_geo_pool_size', 40 );
	}

	/**
	 * How long a shared candidate pool survives, absent an edit.
	 *
	 * Correctness does not depend on this: any post save bumps the cache
	 * generation and orphans every key at once. The TTL only stops orphans
	 * accumulating in wp_options.
	 */
	public static function pool_cache_ttl(): int {
		return (int) apply_filters( 'mavo_for_you_pool_cache_ttl', 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Extra rows fetched so a pool survives having one visitor's history
	 * removed from it.
	 *
	 * The pool is cached without exclusions, because exclusions are the only
	 * personal part of the query. Trimming then happens in PHP, so the query
	 * has to over-fetch by at least the largest possible exclusion list: the
	 * current post, up to max_views() viewed posts, and the placed hubs.
	 */
	public static function pool_overfetch(): int {
		return (int) apply_filters( 'mavo_for_you_pool_overfetch', self::max_views() + 5 );
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

	// -------------------------------------------------------------------------
	// The /pour-vous/ page
	// -------------------------------------------------------------------------

	/**
	 * Languages the standalone suggestions page exists in.
	 *
	 * French only for now — the page is written by hand, and a link pointing
	 * at a page that does not exist in the reader's language is worse than no
	 * link at all. Adding a language here is a one-line change once its page
	 * exists; MFY_Page still verifies that it does before linking to it.
	 */
	public static function page_langs(): array {
		return (array) apply_filters( 'mavo_for_you_page_langs', [ 'fr' ] );
	}

	/** The page's slug, per language. */
	public static function page_slugs(): array {
		return (array) apply_filters( 'mavo_for_you_page_slugs', [
			'fr' => 'pour-vous',
			'en' => 'for-you',
			'de' => 'fuer-euch',
		] );
	}

	/**
	 * Tiles fetched per row.
	 *
	 * Twelve is roughly three screens' worth of a carousel on a desktop and a
	 * dozen swipes on a phone — enough that the strip is worth rotating, few
	 * enough that a row is one bounded query.
	 */
	public static function page_row_size(): int {
		return (int) apply_filters( 'mavo_for_you_page_row_size', 12 );
	}

	/**
	 * Tiles a row must have to be shown at all.
	 *
	 * Four. A strip of three does not rotate — it just sits there with two
	 * arrows that do nothing — and a heading over three tiles promises a
	 * category the site cannot actually fill.
	 */
	public static function page_row_min(): int {
		return (int) apply_filters( 'mavo_for_you_page_row_min', 4 );
	}

	/** Ceiling on how many rows one page may build. */
	public static function page_max_rows(): int {
		return (int) apply_filters( 'mavo_for_you_page_max_rows', 12 );
	}

	/**
	 * Per-kind row caps, so no single signal can fill the page.
	 *
	 * A reader four articles into one hub would otherwise get six hub rows and
	 * nothing else; the point of the page is that each heading is a different
	 * way of looking at the same session.
	 */
	public static function page_rows_per_kind(): array {
		return (array) apply_filters( 'mavo_for_you_page_rows_per_kind', [
			'hub'    => 3,
			'geo'    => 4,
			'filter' => 5,
			'search' => 2,
		] );
	}

	/** Places per geographic level that may become a row. */
	public static function page_geo_places_per_level(): int {
		return (int) apply_filters( 'mavo_for_you_page_geo_places_per_level', 2 );
	}

	/**
	 * Rows of ≥ page_row_min() tiles the session must be able to produce
	 * before the "Pour vous" block links to the page.
	 *
	 * Three. The page is a destination, and a destination with one strip on it
	 * is a disappointment — so the link waits until there is genuinely more to
	 * see than the block itself already shows.
	 */
	public static function page_link_min_rows(): int {
		return (int) apply_filters( 'mavo_for_you_page_link_min_rows', 3 );
	}

	/**
	 * The filters a visitor with no history is shown instead.
	 *
	 * Someone arriving cold — a shared link, a crawler, a first-time reader —
	 * gets rows built from the catalogue rather than from themselves: the
	 * site's most-read articles under a handful of broad filters. It is not
	 * personalization and the page says so, but it is a page rather than a
	 * dead end. Empty means "pick the first few signal slugs", which keeps
	 * this working on a site whose filter vocabulary this plugin has never
	 * seen.
	 *
	 * @return string[]
	 */
	public static function page_fallback_filters(): array {
		return (array) apply_filters( 'mavo_for_you_page_fallback_filters', [] );
	}

	/** How many fallback rows a cold visit may show. */
	public static function page_fallback_rows(): int {
		return (int) apply_filters( 'mavo_for_you_page_fallback_rows', 4 );
	}

	/**
	 * How long a built cold-visit page survives.
	 *
	 * The personalized page is never cached anywhere. The fallback has no
	 * visitor in it at all, so it is the same page for everyone and caching it
	 * is free; the generation counter retires it on any edit, like every other
	 * shared pool.
	 */
	public static function page_fallback_cache_ttl(): int {
		return (int) apply_filters( 'mavo_for_you_page_fallback_cache_ttl', 6 * HOUR_IN_SECONDS );
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
				'impersonalHeading' => __( 'À lire aussi', 'mavo-for-you' ),
				'reset'    => __( 'Effacer mon historique', 'mavo-for-you' ),
				'resetHint' => __( 'Efface les articles consultés enregistrés dans votre navigateur.', 'mavo-for-you' ),
				'resetDone' => __( 'Historique effacé. Les suggestions repartiront de zéro.', 'mavo-for-you' ),
				'pageLink'  => __( 'Voir toutes vos suggestions', 'mavo-for-you' ),
			],
			'en' => [
				'heading'  => __( 'For you', 'mavo-for-you' ),
				'subtitle' => __( "Suggestions based on the articles you've viewed during this visit to Maman Voyage.", 'mavo-for-you' ),
				'recent'   => __( 'Recently viewed', 'mavo-for-you' ),
				'impersonalHeading' => __( 'Also worth reading', 'mavo-for-you' ),
				'reset'    => __( 'Clear my history', 'mavo-for-you' ),
				'resetHint' => __( 'Clears the viewed articles stored in your browser.', 'mavo-for-you' ),
				'resetDone' => __( 'History cleared. Suggestions will start over.', 'mavo-for-you' ),
				'pageLink'  => __( 'See all your suggestions', 'mavo-for-you' ),
			],
			'de' => [
				'heading'  => __( 'Für Euch', 'mavo-for-you' ),
				'subtitle' => __( 'Vorschläge auf Basis der Artikel, die Ihr während dieses Besuchs auf Maman Voyage angesehen habt.', 'mavo-for-you' ),
				'recent'   => __( 'Kürzlich angesehen', 'mavo-for-you' ),
				'impersonalHeading' => __( 'Auch lesenswert', 'mavo-for-you' ),
				'reset'    => __( 'Verlauf löschen', 'mavo-for-you' ),
				'resetHint' => __( 'Löscht die in Eurem Browser gespeicherten angesehenen Artikel.', 'mavo-for-you' ),
				'resetDone' => __( 'Verlauf gelöscht. Die Vorschläge beginnen von vorn.', 'mavo-for-you' ),
				'pageLink'  => __( 'Alle Vorschläge ansehen', 'mavo-for-you' ),
			],
		];

		return (array) apply_filters(
			'mavo_for_you_labels',
			$labels[ $lang ] ?? $labels['en'],
			$lang
		);
	}

	/**
	 * Strings the /pour-vous/ page needs and the block does not.
	 *
	 * Kept apart from labels() so the cached article page does not carry a
	 * dozen row templates it will never render. The row templates are sprintf
	 * patterns taking one substitution — a hub title, a place name, a filter
	 * label, a search query — and they are deliberately written so that no
	 * French article has to be guessed: "Destination Angleterre" reads, where
	 * "Plus sur Angleterre" does not.
	 */
	public static function page_labels( string $lang ): array {
		$labels = [
			'fr' => [
				'loading'    => __( 'Vos suggestions personnalisées se chargent…', 'mavo-for-you' ),
				'intro'      => __( 'Suggestions basées sur les articles consultés pendant votre visite sur Maman Voyage.', 'mavo-for-you' ),
				'rowHub'     => __( 'Dans « %s »', 'mavo-for-you' ),
				'rowGeo'     => __( 'Destination %s', 'mavo-for-you' ),
				'rowFilter'  => __( 'Plus d’articles « %s »', 'mavo-for-you' ),
				'rowSearch'  => __( 'Parce que vous avez cherché « %s »', 'mavo-for-you' ),
				'rowRecent'  => __( 'Consultés récemment', 'mavo-for-you' ),
				'coldIntro'  => __( 'Parcourez quelques articles et vos suggestions personnelles apparaîtront ici. En attendant, voici ce que les lecteurs de Maman Voyage consultent le plus.', 'mavo-for-you' ),
				'empty'      => __( 'Parcourez quelques articles et vos suggestions personnelles apparaîtront ici.', 'mavo-for-you' ),
				'prev'       => __( 'Précédent', 'mavo-for-you' ),
				'next'       => __( 'Suivant', 'mavo-for-you' ),
				'readMark'   => __( 'Déjà lu', 'mavo-for-you' ),
			],
			'en' => [
				'loading'    => __( 'Your personalized suggestions are loading…', 'mavo-for-you' ),
				'intro'      => __( "Suggestions based on the articles you've viewed during this visit to Maman Voyage.", 'mavo-for-you' ),
				'rowHub'     => __( 'Inside “%s”', 'mavo-for-you' ),
				'rowGeo'     => __( 'Destination %s', 'mavo-for-you' ),
				'rowFilter'  => __( 'More “%s” articles', 'mavo-for-you' ),
				'rowSearch'  => __( 'Because you searched for “%s”', 'mavo-for-you' ),
				'rowRecent'  => __( 'Recently viewed', 'mavo-for-you' ),
				'coldIntro'  => __( 'Read a few articles and your own suggestions will appear here. In the meantime, here is what Maman Voyage readers read most.', 'mavo-for-you' ),
				'empty'      => __( 'Read a few articles and your own suggestions will appear here.', 'mavo-for-you' ),
				'prev'       => __( 'Previous', 'mavo-for-you' ),
				'next'       => __( 'Next', 'mavo-for-you' ),
				'readMark'   => __( 'Already read', 'mavo-for-you' ),
			],
			'de' => [
				'loading'    => __( 'Eure persönlichen Vorschläge werden geladen…', 'mavo-for-you' ),
				'intro'      => __( 'Vorschläge auf Basis der Artikel, die Ihr während dieses Besuchs auf Maman Voyage angesehen habt.', 'mavo-for-you' ),
				'rowHub'     => __( 'In „%s“', 'mavo-for-you' ),
				'rowGeo'     => __( 'Reiseziel %s', 'mavo-for-you' ),
				'rowFilter'  => __( 'Mehr Artikel „%s“', 'mavo-for-you' ),
				'rowSearch'  => __( 'Weil Ihr nach „%s“ gesucht habt', 'mavo-for-you' ),
				'rowRecent'  => __( 'Kürzlich angesehen', 'mavo-for-you' ),
				'coldIntro'  => __( 'Lest ein paar Artikel, dann erscheinen hier Eure eigenen Vorschläge. Solange zeigen wir, was die Leserinnen und Leser von Maman Voyage am häufigsten lesen.', 'mavo-for-you' ),
				'empty'      => __( 'Lest ein paar Artikel, dann erscheinen hier Eure eigenen Vorschläge.', 'mavo-for-you' ),
				'prev'       => __( 'Zurück', 'mavo-for-you' ),
				'next'       => __( 'Weiter', 'mavo-for-you' ),
				'readMark'   => __( 'Schon gelesen', 'mavo-for-you' ),
			],
		];

		return (array) apply_filters(
			'mavo_for_you_page_labels',
			$labels[ $lang ] ?? $labels['en'],
			$lang
		);
	}
}

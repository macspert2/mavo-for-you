<?php
/**
 * The boundary between this plugin and the site's geographic data, exactly as
 * MFY_Data is the boundary to the travel-finder scores. Nothing here knows what
 * a tvf filter is, and nothing in MFY_Data knows what a place is.
 *
 * Geo Tagger models places as a parent tree (continent → country → region →
 * city) in {prefix}geo_tagger_places, and attaches *every* level of a post's
 * chain to it as a post_tag, per language. So a post's geography is readable in
 * one join, for any number of posts at once — no per-post chain walk.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Geo {

	/** The place table only has name/term columns for these languages. */
	const SUPPORTED_LANGS = [ 'fr', 'en', 'de' ];

	private static ?bool $available = null;

	/** Per-request memo of place names, keyed "place_id:lang". */
	private static array $name_cache = [];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'geo_tagger_places';
	}

	/**
	 * Is geographic data usable at all?
	 *
	 * Checked once per request. When it is false every geo feature switches
	 * off and ranking falls back to filter scores alone — which is exactly the
	 * behaviour the plugin had before geography existed.
	 */
	public static function available(): bool {
		if ( null !== self::$available ) {
			return self::$available;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return self::$available = ( $found === $table );
	}

	public static function supports_lang( string $lang ): bool {
		return in_array( $lang, self::SUPPORTED_LANGS, true );
	}

	/** The levels that take part in matching, most specific first. */
	public static function levels(): array {
		return MFY_Config::geo_levels();
	}

	/**
	 * Each post's place at each level: [ post_id => [ level => place_id ] ].
	 *
	 * One query for the whole set. Posts with no geographic tags simply come
	 * back with an empty map rather than being absent, so callers never have
	 * to distinguish "not geo-tagged" from "unknown post".
	 *
	 * @param int[] $post_ids
	 */
	public static function places_for_posts( array $post_ids, string $lang ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$out      = array_fill_keys( $post_ids, [] );

		if ( ! $post_ids || ! self::available() || ! self::supports_lang( $lang ) ) {
			return $out;
		}

		global $wpdb;
		$table  = self::table();
		$levels = self::levels();
		$col    = 'term_id_' . $lang; // Constrained to SUPPORTED_LANGS above.

		$id_ph    = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$level_ph = implode( ',', array_fill( 0, count( $levels ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id AS post_id, gp.id AS place_id, gp.level
				   FROM {$wpdb->term_relationships} tr
				   JOIN {$wpdb->term_taxonomy} tt
				     ON tt.term_taxonomy_id = tr.term_taxonomy_id
				    AND tt.taxonomy = 'post_tag'
				   JOIN {$table} gp
				     ON gp.{$col} = tt.term_id
				  WHERE tr.object_id IN ({$id_ph})
				    AND gp.level IN ({$level_ph})",
				...array_merge( $post_ids, $levels )
			),
			ARRAY_A
		);

		foreach ( $rows ?: [] as $row ) {
			$post_id = (int) $row['post_id'];
			$level   = (string) $row['level'];

			// A post carrying two places at the same level would be a tagging
			// accident; the lowest ID wins so the result stays deterministic.
			if ( isset( $out[ $post_id ] ) && ! isset( $out[ $post_id ][ $level ] ) ) {
				$out[ $post_id ][ $level ] = (int) $row['place_id'];
			}
		}

		return $out;
	}

	/**
	 * Published candidate posts tagged with any of these places.
	 *
	 * This is what lets a strongly local match surface even when it shares no
	 * travel-finder filter with the session: three London articles should be
	 * able to recommend a fourth London article on geography alone. Editorial
	 * eligibility is still applied afterwards by MFY_Data.
	 *
	 * @param int[] $place_ids
	 * @param int[] $exclude_ids
	 * @return int[]
	 */
	public static function candidate_ids( string $lang, array $place_ids, array $exclude_ids, int $limit ): array {
		$place_ids = array_values( array_unique( array_filter( array_map( 'absint', $place_ids ) ) ) );

		if ( ! $place_ids || ! self::available() || ! self::supports_lang( $lang ) ) {
			return [];
		}

		global $wpdb;
		$table      = self::table();
		$col        = 'term_id_' . $lang;
		$post_types = MFY_Config::candidate_post_types();
		$exclude    = array_values( array_unique( array_filter( array_map( 'absint', $exclude_ids ) ) ) );
		$limit      = max( 1, min( 200, $limit ) );

		$place_ph = implode( ',', array_fill( 0, count( $place_ids ), '%d' ) );
		$type_ph  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args     = array_merge( $post_types, $place_ids );

		$exclude_sql = '';
		if ( $exclude ) {
			$exclude_sql = ' AND p.ID NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$args        = array_merge( $args, $exclude );
		}

		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				   JOIN {$wpdb->term_taxonomy} tt
				     ON tt.term_taxonomy_id = tr.term_taxonomy_id
				    AND tt.taxonomy = 'post_tag'
				   JOIN {$table} gp ON gp.{$col} = tt.term_id
				  WHERE p.post_status = 'publish'
				    AND p.post_type IN ({$type_ph})
				    AND gp.{$col} IS NOT NULL
				    AND gp.id IN ({$place_ph})
				    {$exclude_sql}
				  ORDER BY p.post_date DESC
				  LIMIT %d",
				...$args
			)
		);

		return array_map( 'absint', $ids ?: [] );
	}

	/** Display names for places, for debug output. [ place_id => name ]. */
	public static function place_names( array $place_ids, string $lang ): array {
		$place_ids = array_values( array_unique( array_filter( array_map( 'absint', $place_ids ) ) ) );
		$out       = [];
		$missing   = [];

		foreach ( $place_ids as $place_id ) {
			$key = $place_id . ':' . $lang;
			if ( isset( self::$name_cache[ $key ] ) ) {
				$out[ $place_id ] = self::$name_cache[ $key ];
			} else {
				$missing[] = $place_id;
			}
		}

		if ( ! $missing || ! self::available() || ! self::supports_lang( $lang ) ) {
			return $out;
		}

		global $wpdb;
		$table = self::table();
		$col   = 'name_' . $lang;
		$ph    = implode( ',', array_fill( 0, count( $missing ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, {$col} AS name FROM {$table} WHERE id IN ({$ph})", ...$missing ),
			ARRAY_A
		);

		foreach ( $rows ?: [] as $row ) {
			$id  = (int) $row['id'];
			$out[ $id ] = (string) ( $row['name'] ?? '' );
			self::$name_cache[ $id . ':' . $lang ] = $out[ $id ];
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Session geography
	// -------------------------------------------------------------------------

	/**
	 * Where this session has been reading about, and whether it is focused.
	 *
	 * Each viewed post contributes its own weight (recency × engagement, the
	 * same figure the filter scoring uses) to its place at every level. A level
	 * is "focused" when one place holds at least the threshold share of the
	 * weight of the views that *have* a place at that level — a post with no
	 * geography is not evidence of dispersion, so it stays out of the
	 * denominator entirely.
	 *
	 * The result carries a chain rather than a single verdict: three London
	 * posts are focused on London (city), on Greater London (region) and on
	 * England (country) all at once, and the slot reservation walks outwards
	 * through exactly that chain when the city has too little unread material.
	 *
	 * @param array $weighted_views [ [ 'post_id' => int, 'weight' => float ], … ]
	 */
	public static function session_profile( array $weighted_views, string $lang, ?array $levels = null ): array {
		// A caller may narrow the levels — [geo_related level="country"] says
		// "organise this around the country", and the profile it gets back
		// then knows about nothing else.
		$levels = $levels ? array_values( array_intersect( self::levels(), $levels ) ) : self::levels();

		$profile = [
			'by_level' => [],
			'totals'   => [],
			'chain'    => [],
			'places'   => [],
			'levels'   => $levels,
		];

		if ( ! $weighted_views || ! $levels || ! self::available() || ! self::supports_lang( $lang ) ) {
			return $profile;
		}

		$places = self::places_for_posts( array_column( $weighted_views, 'post_id' ), $lang );

		foreach ( $weighted_views as $view ) {
			$weight = (float) $view['weight'];
			$map    = $places[ $view['post_id'] ] ?? [];

			foreach ( $levels as $level ) {
				if ( empty( $map[ $level ] ) ) {
					continue;
				}

				$place_id = (int) $map[ $level ];

				$profile['by_level'][ $level ][ $place_id ] = ( $profile['by_level'][ $level ][ $place_id ] ?? 0.0 ) + $weight;
				$profile['totals'][ $level ]                = ( $profile['totals'][ $level ] ?? 0.0 ) + $weight;
			}
		}

		$threshold = MFY_Config::geo_focus_threshold();

		foreach ( $levels as $level ) {
			$weights = $profile['by_level'][ $level ] ?? [];
			$total   = $profile['totals'][ $level ] ?? 0.0;

			if ( ! $weights || $total <= 0 ) {
				continue;
			}

			arsort( $weights );
			$profile['by_level'][ $level ] = $weights;

			$place_id = (int) array_key_first( $weights );
			$share    = $weights[ $place_id ] / $total;

			if ( $share >= $threshold ) {
				$profile['chain'][] = [
					'level'    => $level,
					'place_id' => $place_id,
					'share'    => round( $share, 3 ),
					'weight'   => round( $weights[ $place_id ], 3 ),
				];
			}
		}

		$profile['places'] = self::place_names(
			array_merge(
				array_column( $profile['chain'], 'place_id' ),
				array_reduce(
					array_values( $profile['by_level'] ),
					static fn( $carry, $level_weights ) => array_merge( $carry, array_keys( $level_weights ) ),
					[]
				)
			),
			$lang
		);

		return $profile;
	}

	/**
	 * The deepest level at which a candidate sits in a place this session has
	 * been reading about, with the points that earns.
	 *
	 * Deepest-match-only, deliberately: a London article is in London, in
	 * Greater London and in England, and awarding all three would triple-count
	 * one fact and make the numbers unreadable.
	 *
	 * @return array{level: string, place_id: int, points: float}|null
	 */
	public static function affinity( array $candidate_places, array $profile ): ?array {
		if ( ! $candidate_places || empty( $profile['by_level'] ) ) {
			return null;
		}

		$points_by_level = MFY_Config::geo_points();

		foreach ( $profile['levels'] ?? self::levels() as $level ) {
			if ( empty( $candidate_places[ $level ] ) ) {
				continue;
			}

			$place_id = (int) $candidate_places[ $level ];
			$weight   = $profile['by_level'][ $level ][ $place_id ] ?? 0.0;

			if ( $weight <= 0 ) {
				continue;
			}

			return [
				'level'    => $level,
				'place_id' => $place_id,
				'points'   => (float) ( $points_by_level[ $level ] ?? 0.0 ) * $weight,
			];
		}

		return null;
	}
}

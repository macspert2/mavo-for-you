<?php
/**
 * The single boundary between this plugin and the site's editorial data.
 *
 * Every "où partir" score read goes through here, so the day the travel-finder
 * changes how it stores weights, only this file moves. No scoring code may
 * touch TVF_Store, the tvf table or a meta key directly.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Data {

	/** Per-request memo of filter scores, keyed "post_id:lang". */
	private static array $score_cache = [];

	/**
	 * Is the travel-finder filter data reachable?
	 *
	 * Without it there is no signal at all, and V0 deliberately shows nothing
	 * rather than falling back to generic or random picks.
	 */
	public static function integration_available(): bool {
		return function_exists( 'tvf_get_registry' ) && class_exists( 'TVF_Store' );
	}

	/**
	 * The filter slugs that carry the "où partir" signal, i.e. every slug of
	 * the configured signal categories.
	 *
	 * @return string[]
	 */
	public static function signal_slugs(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		if ( ! self::integration_available() ) {
			return $cache = [];
		}

		$categories = array_flip( MFY_Config::signal_categories() );
		$slugs      = [];

		foreach ( tvf_get_registry() as $cat_slug => $cat ) {
			if ( ! isset( $categories[ $cat_slug ] ) ) {
				continue;
			}
			foreach ( array_keys( $cat['filters'] ) as $slug ) {
				$slugs[] = $slug;
			}
		}

		return $cache = $slugs;
	}

	/** [ slug => human label ] for the signal slugs, for debug output. */
	public static function signal_labels(): array {
		if ( ! self::integration_available() ) {
			return [];
		}

		return array_intersect_key( tvf_get_slug_labels(), array_flip( self::signal_slugs() ) );
	}

	/**
	 * Normalized "où partir" scores for one post: [ slug => 0|1|2 ].
	 *
	 * Slugs with no stored row are returned as 0, so callers never have to
	 * distinguish "absent" from "not applicable".
	 */
	public static function get_filter_scores( int $post_id, string $lang ): array {
		$key = $post_id . ':' . $lang;
		if ( isset( self::$score_cache[ $key ] ) ) {
			return self::$score_cache[ $key ];
		}

		$scores = array_fill_keys( self::signal_slugs(), 0 );

		if ( self::integration_available() && $scores ) {
			foreach ( TVF_Store::get_weights( $post_id, $lang ) as $slug => $weight ) {
				if ( array_key_exists( $slug, $scores ) ) {
					$scores[ $slug ] = max( 0, min( 2, (int) $weight ) );
				}
			}
		}

		return self::$score_cache[ $key ] = $scores;
	}

	/**
	 * The same thing for many posts in one query — the candidate pool would
	 * otherwise be one round trip per candidate.
	 *
	 * @param int[] $post_ids
	 * @return array<int, array<string,int>>
	 */
	public static function get_filter_scores_bulk( array $post_ids, string $lang ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$slugs    = self::signal_slugs();
		$out      = [];

		foreach ( $post_ids as $post_id ) {
			$out[ $post_id ] = array_fill_keys( $slugs, 0 );
		}

		if ( ! $post_ids || ! $slugs || ! self::integration_available() ) {
			return $out;
		}

		global $wpdb;
		$table   = TVF_Store::table_name();
		$id_ph   = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$slug_ph = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$args    = array_merge( $post_ids, [ $lang ], $slugs );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, filter_slug, weight
				   FROM {$table}
				  WHERE post_id IN ({$id_ph})
				    AND lang = %s
				    AND filter_slug IN ({$slug_ph})",
				...$args
			),
			ARRAY_A
		);

		foreach ( $rows ?: [] as $row ) {
			$post_id = (int) $row['post_id'];
			if ( isset( $out[ $post_id ] ) ) {
				$out[ $post_id ][ $row['filter_slug'] ] = max( 0, min( 2, (int) $row['weight'] ) );
			}
		}

		// Warm the per-post memo so a later single lookup costs nothing.
		foreach ( $out as $post_id => $scores ) {
			self::$score_cache[ $post_id . ':' . $lang ] = $scores;
		}

		return $out;
	}

	/**
	 * The bounded candidate pool: published posts of the current language
	 * carrying a score of 2 in at least one of the session's strong filters.
	 *
	 * One indexed query — (lang, filter_slug) covers the WHERE, and the
	 * exclusion list is at most a couple of dozen IDs, so the pool is capped
	 * long before PHP scoring runs. Ordering by the number of matched strong
	 * filters (then by the site's own view counter as a stable tie-break)
	 * means the LIMIT truncates the least promising candidates, not arbitrary
	 * ones.
	 *
	 * @param string[] $strong_slugs Session filters scored 2. Bounded by the registry.
	 * @param int[]    $exclude_ids  Current post + everything already viewed.
	 * @return array<int, array{post_id:int, hits:int, views:int}>
	 */
	public static function get_candidates( string $lang, array $strong_slugs, array $exclude_ids, int $limit ): array {
		$strong_slugs = array_values( array_intersect( $strong_slugs, self::signal_slugs() ) );

		if ( ! $strong_slugs || ! self::integration_available() ) {
			return [];
		}

		global $wpdb;
		$table      = TVF_Store::table_name();
		$post_types = MFY_Config::candidate_post_types();
		$exclude    = array_values( array_unique( array_filter( array_map( 'absint', $exclude_ids ) ) ) );
		$limit      = max( 1, min( 200, $limit ) );

		$slug_ph = implode( ',', array_fill( 0, count( $strong_slugs ), '%s' ) );
		$type_ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args    = array_merge( $post_types, [ $lang ], $strong_slugs );

		$exclude_sql = '';
		if ( $exclude ) {
			$exclude_sql = ' AND pf.post_id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$args        = array_merge( $args, $exclude );
		}

		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pf.post_id,
				        COUNT(*) AS hits,
				        CAST( COALESCE( pm.meta_value, 0 ) AS UNSIGNED ) AS views
				   FROM {$table} pf
				   JOIN {$wpdb->posts} p
				     ON p.ID = pf.post_id
				    AND p.post_status = 'publish'
				    AND p.post_type IN ({$type_ph})
				   LEFT JOIN {$wpdb->postmeta} pm
				     ON pm.post_id = pf.post_id AND pm.meta_key = 'views'
				  WHERE pf.lang = %s
				    AND pf.filter_slug IN ({$slug_ph})
				    AND pf.weight = 2
				    {$exclude_sql}
				  GROUP BY pf.post_id
				  ORDER BY hits DESC, views DESC, pf.post_id ASC
				  LIMIT %d",
				...$args
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[] = [
				'post_id' => (int) $row['post_id'],
				'hits'    => (int) $row['hits'],
				'views'   => (int) $row['views'],
			];
		}

		return $out;
	}

	/**
	 * Editorial eligibility for an already-chosen set of post IDs.
	 *
	 * The geography pool arrives as bare IDs (MFY_Geo knows nothing about
	 * filter scores), and §10's rule still applies to every candidate however
	 * it was found: at least one "où partir" filter scored 2. This applies it,
	 * and returns the same row shape as get_candidates() so the two pools can
	 * simply be merged.
	 *
	 * @param int[] $post_ids
	 * @param int[] $exclude_ids
	 * @return array<int, array{post_id:int, hits:int, views:int}>
	 */
	public static function filter_eligible( string $lang, array $post_ids, array $exclude_ids = [] ): array {
		$post_ids = array_values( array_diff(
			array_unique( array_filter( array_map( 'absint', $post_ids ) ) ),
			array_map( 'absint', $exclude_ids )
		) );
		$slugs    = self::signal_slugs();

		if ( ! $post_ids || ! $slugs || ! self::integration_available() ) {
			return [];
		}

		global $wpdb;
		$table      = TVF_Store::table_name();
		$post_types = MFY_Config::candidate_post_types();

		$id_ph   = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$slug_ph = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$type_ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$args    = array_merge( $post_types, [ $lang ], $post_ids, $slugs );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pf.post_id,
				        COUNT(*) AS hits,
				        CAST( COALESCE( pm.meta_value, 0 ) AS UNSIGNED ) AS views
				   FROM {$table} pf
				   JOIN {$wpdb->posts} p
				     ON p.ID = pf.post_id
				    AND p.post_status = 'publish'
				    AND p.post_type IN ({$type_ph})
				   LEFT JOIN {$wpdb->postmeta} pm
				     ON pm.post_id = pf.post_id AND pm.meta_key = 'views'
				  WHERE pf.lang = %s
				    AND pf.post_id IN ({$id_ph})
				    AND pf.filter_slug IN ({$slug_ph})
				    AND pf.weight = 2
				  GROUP BY pf.post_id",
				...$args
			),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$out[] = [
				'post_id' => (int) $row['post_id'],
				'hits'    => (int) $row['hits'],
				'views'   => (int) $row['views'],
			];
		}

		return $out;
	}

	/**
	 * A post's Polylang language slug, or '' when it cannot be determined.
	 *
	 * Callers treat '' as "do not use this post" — mixing languages is worse
	 * than showing nothing.
	 */
	public static function post_lang( int $post_id ): string {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return '';
		}

		return (string) ( pll_get_post_language( $post_id, 'slug' ) ?: '' );
	}

	/** Is this a published post/page of one of the given types? */
	public static function is_valid_post( int $post_id, array $post_types ): bool {
		$post = get_post( $post_id );

		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, $post_types, true );
	}

	/**
	 * Should this post's views enter the profile at all?
	 *
	 * The single answer to that question, so the browser and the endpoint can
	 * never disagree: the frontend uses it to decide whether to track, and the
	 * REST layer re-applies it to every view it is handed, because a profile
	 * recorded before this rule existed — or by a client that ignores it — must
	 * not smuggle a contact page back in.
	 *
	 * The `mavo_for_you_track_post` filter has the last word in both places,
	 * so a site can re-enable a page this refuses, or exclude one it allows.
	 */
	public static function should_track_post( int $post_id ): bool {
		return (bool) apply_filters(
			'mavo_for_you_track_post',
			! self::is_utility_page( $post_id ),
			$post_id
		);
	}

	/**
	 * Contact, privacy and legal pages (§3).
	 *
	 * They carry no filter scores, no geography and no hub, so they add
	 * nothing to a profile — but left in they would still count toward the
	 * two-meaningful-views threshold and could surface as "Consultés
	 * récemment : Contact", which reads as a bug.
	 */
	public static function is_utility_page( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return false;
		}

		if ( in_array( $post_id, self::privacy_page_ids(), true ) ) {
			return true;
		}

		// "contact-en" and "impressum-2" are the same page as far as this is
		// concerned: Polylang and WordPress both suffix slugs on collision.
		$slug = preg_replace( '/-(?:[a-z]{2}|\d+)$/', '', (string) $post->post_name );

		return in_array( $slug, MFY_Config::excluded_page_slugs(), true )
			|| in_array( (string) $post->post_name, MFY_Config::excluded_page_slugs(), true );
	}

	/**
	 * The privacy policy page, and its translations.
	 *
	 * WordPress stores one ID; on a Polylang site the other languages have
	 * their own pages, and all of them are the privacy policy.
	 *
	 * @return int[]
	 */
	private static function privacy_page_ids(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );

		if ( ! $privacy_id ) {
			return $cache = [];
		}

		$ids = [ $privacy_id ];

		if ( function_exists( 'pll_get_post' ) ) {
			foreach ( MFY_Config::site_langs() as $lang ) {
				$translated = (int) pll_get_post( $privacy_id, $lang );
				if ( $translated ) {
					$ids[] = $translated;
				}
			}
		}

		return $cache = array_values( array_unique( $ids ) );
	}

	/**
	 * The public shape of one recommendation / recently-viewed entry.
	 *
	 * @param bool $with_excerpt Recently-viewed items are compact and skip it.
	 */
	public static function format_item( int $post_id, bool $with_excerpt = true ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return [];
		}

		$item = [
			'post_id' => $post_id,
			'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'     => (string) get_permalink( $post ),
			'image'   => (string) ( get_the_post_thumbnail_url( $post, 'medium_large' ) ?: '' ),
		];

		if ( $with_excerpt ) {
			$item['excerpt'] = wp_strip_all_tags( html_entity_decode( get_the_excerpt( $post ), ENT_QUOTES, 'UTF-8' ) );
		}

		return $item;
	}
}

/**
 * Data attribute other Mavo plugins/templates can drop on internal links so a
 * future "already read" pass can match them against the local history.
 *
 * Returns a leading-space attribute string ready to concatenate inside a tag,
 * or '' for an invalid ID.
 */
function mavo_for_you_link_data_attr( int $post_id ): string {
	$post_id = absint( $post_id );

	return $post_id ? ' data-mavo-post-id="' . esc_attr( (string) $post_id ) . '"' : '';
}

/**
 * Normalized "où partir" scores for a post: [ slug => 0|1|2 ].
 *
 * The documented accessor other Mavo code should call; MFY_Data is the
 * implementation behind it.
 */
function mavo_for_you_get_filter_scores( int $post_id, string $lang = '' ): array {
	if ( '' === $lang ) {
		$lang = MFY_Data::post_lang( $post_id ) ?: 'fr';
	}

	return MFY_Data::get_filter_scores( $post_id, $lang );
}

<?php
/**
 * The ranking. Deterministic, explainable, no external anything.
 *
 * The shape of it: recently viewed posts each contribute their strongly scored
 * ("où partir" = 2) filters to a session interest map, weighted by how recent
 * the view was and how engaged the reading looked. Candidates are then scored
 * on how well their own filters overlap that map.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Scorer {

	/**
	 * @param array  $views    Validated views, each [post_id, duration_seconds, max_scroll_pct, last_seen].
	 * @param array  $searches Validated searches, each [query, source, timestamp].
	 * @param array|null $referral Validated referral, or null.
	 * @return array{recommendations: array, debug: array}
	 */
	public static function rank( int $current_post_id, string $lang, array $views, array $searches, ?array $referral ): array {
		$debug = [
			'lang'            => $lang,
			'profile_views'   => [],
			'profile_filters' => [],
			'search_filters'  => [],
			'pool_size'       => 0,
			'candidates'      => [],
			'excluded'        => [],
		];

		$ordered = self::order_views( $current_post_id, $views );

		[ $interest, $view_debug ] = self::build_interest_profile( $ordered, $lang );
		$debug['profile_views']    = $view_debug;
		$debug['profile_filters']  = $interest;

		$search_filters         = self::search_filters( $searches, $referral, $lang );
		$debug['search_filters'] = $search_filters;

		$pool_slugs = array_values( array_unique( array_merge( array_keys( $interest ), array_keys( $search_filters ) ) ) );

		if ( ! $pool_slugs ) {
			$debug['excluded'][] = 'no strong session filters — nothing to match on';
			return [ 'recommendations' => [], 'debug' => $debug ];
		}

		$exclude    = array_merge( [ $current_post_id ], array_column( $ordered, 'post_id' ) );
		$candidates = MFY_Data::get_candidates( $lang, $pool_slugs, $exclude, MFY_Config::candidate_pool_size() );
		$debug['pool_size'] = count( $candidates );

		if ( ! $candidates ) {
			return [ 'recommendations' => [], 'debug' => $debug ];
		}

		$scores_by_post = MFY_Data::get_filter_scores_bulk( array_column( $candidates, 'post_id' ), $lang );
		$min_score      = MFY_Config::min_score( [
			'lang'            => $lang,
			'current_post_id' => $current_post_id,
		] );

		$scored = [];
		foreach ( $candidates as $candidate ) {
			$post_id = $candidate['post_id'];
			$scores  = $scores_by_post[ $post_id ] ?? [];

			[ $total, $reasons, $matched_strong ] = self::score_candidate( $scores, $interest, $search_filters );

			$debug['candidates'][] = [
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'score'   => round( $total, 2 ),
				'reasons' => $reasons,
			];

			if ( $total < $min_score ) {
				$debug['excluded'][] = sprintf( '%d below min score (%.2f < %.2f)', $post_id, $total, $min_score );
				continue;
			}

			$scored[] = [
				'post_id'   => $post_id,
				'score'     => $total,
				'views'     => $candidate['views'],
				'signature' => $matched_strong,
			];
		}

		usort( $scored, static function ( $a, $b ) {
			return [ $b['score'], $b['views'], $a['post_id'] ] <=> [ $a['score'], $a['views'], $b['post_id'] ];
		} );

		$picked = self::pick( $scored, $lang, MFY_Config::num_recommendations(), $debug );

		$recommendations = [];
		foreach ( $picked as $entry ) {
			$item = MFY_Data::format_item( $entry['post_id'] );
			if ( ! $item ) {
				continue;
			}
			$item['score']     = round( $entry['score'], 2 );
			$recommendations[] = $item;
		}

		usort( $debug['candidates'], static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return [ 'recommendations' => $recommendations, 'debug' => $debug ];
	}

	// -------------------------------------------------------------------------
	// Session profile
	// -------------------------------------------------------------------------

	/**
	 * Views most-recent-first, with the current page forced into first place.
	 *
	 * The page being read right now is the strongest signal there is, whatever
	 * timestamp the client happened to send for it.
	 */
	private static function order_views( int $current_post_id, array $views ): array {
		usort( $views, static fn( $a, $b ) => $b['last_seen'] <=> $a['last_seen'] );

		$current = null;
		$rest    = [];
		foreach ( $views as $view ) {
			if ( null === $current && $view['post_id'] === $current_post_id ) {
				$current = $view;
				continue;
			}
			if ( $view['post_id'] === $current_post_id ) {
				continue; // Defensive: a duplicate entry for the same post.
			}
			$rest[] = $view;
		}

		return null === $current ? $rest : array_merge( [ $current ], $rest );
	}

	/**
	 * The session interest map: [ filter_slug => accumulated weight ].
	 *
	 * Only filters a viewed post scores 2 in contribute. A filter scored 2 by
	 * several recent posts accumulates, which is exactly the "this visitor is
	 * clearly after city trips with teenagers" case.
	 *
	 * @return array{0: array<string,float>, 1: array}
	 */
	private static function build_interest_profile( array $ordered_views, string $lang ): array {
		$weights  = array_values( MFY_Config::recency_weights() );
		$last     = end( $weights ) ?: 0.3;
		$interest = [];
		$debug    = [];

		$scores_by_post = MFY_Data::get_filter_scores_bulk( array_column( $ordered_views, 'post_id' ), $lang );

		foreach ( array_values( $ordered_views ) as $index => $view ) {
			$recency    = $weights[ $index ] ?? $last;
			$engagement = self::engagement_multiplier( $view['duration_seconds'], $view['max_scroll_pct'] );
			$scores     = $scores_by_post[ $view['post_id'] ] ?? [];
			$strong     = [];

			foreach ( $scores as $slug => $score ) {
				if ( 2 !== (int) $score ) {
					continue;
				}
				$strong[] = $slug;
				$interest[ $slug ] = ( $interest[ $slug ] ?? 0.0 ) + ( $recency * $engagement );
			}

			$debug[] = [
				'post_id'          => $view['post_id'],
				'title'            => get_the_title( $view['post_id'] ),
				'recency'          => $recency,
				'duration_seconds' => $view['duration_seconds'],
				'duration_mult'    => self::duration_multiplier( $view['duration_seconds'] ),
				'max_scroll_pct'   => $view['max_scroll_pct'],
				'scroll_mult'      => self::scroll_multiplier( $view['max_scroll_pct'] ),
				'engagement'       => round( $engagement, 3 ),
				'strong_filters'   => $strong,
			];
		}

		arsort( $interest );

		return [ array_map( static fn( $v ) => round( $v, 4 ), $interest ), $debug ];
	}

	/**
	 * Duration and scroll averaged, then clamped.
	 *
	 * Averaged rather than multiplied on purpose: multiplying two independent
	 * 1.25s would hand a single well-read page 1.56× and let it drown out
	 * everything else.
	 */
	public static function engagement_multiplier( int $duration, int $scroll ): float {
		$combined = ( self::duration_multiplier( $duration ) + self::scroll_multiplier( $scroll ) ) / 2;
		[ $min, $max ] = MFY_Config::engagement_bounds();

		return (float) max( $min, min( $max, $combined ) );
	}

	public static function duration_multiplier( int $duration ): float {
		return self::step_multiplier( $duration, MFY_Config::duration_multipliers() );
	}

	public static function scroll_multiplier( int $scroll ): float {
		return self::step_multiplier( $scroll, MFY_Config::scroll_multipliers() );
	}

	/** First multiplier whose threshold the value reaches, thresholds descending. */
	private static function step_multiplier( int $value, array $table ): float {
		krsort( $table, SORT_NUMERIC );

		foreach ( $table as $threshold => $multiplier ) {
			if ( $value >= (int) $threshold ) {
				return (float) $multiplier;
			}
		}

		return 1.0;
	}

	// -------------------------------------------------------------------------
	// Search influence
	// -------------------------------------------------------------------------

	/**
	 * Search terms mapped onto filter slugs: [ slug => weight ].
	 *
	 * On-site searches are the visitor stating an intent in their own words,
	 * so they weigh 1. A query recovered from an external referrer is a weaker
	 * signal and is scaled down. A referrer with no query contributes nothing —
	 * V0 never invents keywords from a bare "google.com".
	 */
	private static function search_filters( array $searches, ?array $referral, string $lang ): array {
		$map = MFY_Config::search_filter_map( $lang );
		$out = [];

		$queries = [];
		foreach ( $searches as $search ) {
			$queries[] = [ $search['query'], 1.0 ];
		}
		if ( $referral && ! empty( $referral['query'] ) ) {
			$queries[] = [ $referral['query'], MFY_Config::referral_search_factor() ];
		}

		$signal_slugs = array_flip( MFY_Data::signal_slugs() );

		foreach ( $queries as [ $query, $weight ] ) {
			$normalized = self::normalize_query( $query );
			if ( '' === $normalized ) {
				continue;
			}

			foreach ( $map as $token => $slug ) {
				if ( ! isset( $signal_slugs[ $slug ] ) ) {
					continue;
				}
				// Prefix-at-word-boundary, so "rando" catches "randonnée" but
				// not "durando".
				if ( preg_match( '/\b' . preg_quote( self::normalize_query( $token ), '/' ) . '/u', $normalized ) ) {
					$out[ $slug ] = max( $out[ $slug ] ?? 0.0, (float) $weight );
				}
			}
		}

		return $out;
	}

	/** Lowercased, accent-folded, whitespace-collapsed. */
	private static function normalize_query( string $query ): string {
		$query = remove_accents( $query );
		$query = strtolower( $query );

		return trim( preg_replace( '/\s+/u', ' ', $query ) );
	}

	// -------------------------------------------------------------------------
	// Candidate scoring
	// -------------------------------------------------------------------------

	/**
	 * @return array{0: float, 1: string[], 2: string[]} score, human reasons, matched strong slugs.
	 */
	private static function score_candidate( array $scores, array $interest, array $search_filters ): array {
		$labels  = MFY_Data::signal_labels();
		$total   = 0.0;
		$reasons = [];
		$strong  = [];

		$strong_points = MFY_Config::points_strong_match();
		$weak_points   = MFY_Config::points_weak_match();

		foreach ( $interest as $slug => $weight ) {
			$candidate_score = (int) ( $scores[ $slug ] ?? 0 );
			if ( 0 === $candidate_score ) {
				continue;
			}

			$points  = ( 2 === $candidate_score ? $strong_points : $weak_points ) * $weight;
			$total  += $points;
			$reasons[] = sprintf(
				'+%.2f %s (%s, session weight %.2f)',
				$points,
				$labels[ $slug ] ?? $slug,
				2 === $candidate_score ? 'shared strong filter' : 'partial match',
				$weight
			);

			if ( 2 === $candidate_score ) {
				$strong[] = $slug;
			}
		}

		foreach ( $search_filters as $slug => $weight ) {
			$candidate_score = (int) ( $scores[ $slug ] ?? 0 );
			if ( 0 === $candidate_score ) {
				continue;
			}

			$points = ( 2 === $candidate_score ? MFY_Config::points_search_strong() : MFY_Config::points_search_weak() ) * $weight;
			$total += $points;
			$reasons[] = sprintf( '+%.2f %s (search-term mapping)', $points, $labels[ $slug ] ?? $slug );
		}

		sort( $strong );

		return [ $total, $reasons, $strong ];
	}

	/**
	 * Top N, verified for language, with a light diversity pass.
	 *
	 * Two candidates matching on exactly the same set of strong filters are
	 * near-interchangeable to the reader, so the second one steps aside for
	 * anything more varied. If nothing more varied exists it comes back — a
	 * slightly repetitive third card beats an empty slot.
	 */
	private static function pick( array $scored, string $lang, int $limit, array &$debug ): array {
		$picked      = [];
		$deferred    = [];
		$signatures  = [];

		foreach ( $scored as $entry ) {
			if ( count( $picked ) >= $limit ) {
				break;
			}

			// Language is enforced by the pool query via the tvf lang column;
			// this re-checks the handful of posts actually about to be shown
			// against Polylang itself.
			$post_lang = MFY_Data::post_lang( $entry['post_id'] );
			if ( $post_lang !== $lang ) {
				$debug['excluded'][] = sprintf( '%d language mismatch (%s ≠ %s)', $entry['post_id'], $post_lang ?: '?', $lang );
				continue;
			}

			$signature = implode( '|', $entry['signature'] );
			if ( '' !== $signature && isset( $signatures[ $signature ] ) ) {
				$deferred[] = $entry;
				$debug['excluded'][] = sprintf( '%d deferred by diversity pass (same strong profile: %s)', $entry['post_id'], $signature );
				continue;
			}

			$signatures[ $signature ] = true;
			$picked[]                 = $entry;
		}

		foreach ( $deferred as $entry ) {
			if ( count( $picked ) >= $limit ) {
				break;
			}
			$picked[] = $entry;
		}

		return $picked;
	}
}

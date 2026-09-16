<?php
/**
 * The ranking. Deterministic, explainable, no external anything.
 *
 * The shape of it: recently viewed posts each contribute their strongly scored
 * ("où partir" = 2) filters to a session interest map, and their location to a
 * session geography, both weighted by how recent the view was and how engaged
 * the reading looked. Candidates are then scored on how well they overlap the
 * two.
 *
 * Filter scores describe what *kind* of trip an article is about; geography
 * describes *where*. A reader three articles deep into London is telling us
 * both things, and the second one used to be invisible — which is how a
 * Barcelona article that happened to share "city trip + teenagers" could
 * outrank the next London article. Composition (see pick()) is what fixes
 * that: when a session is clearly focused on one place, most of the slots are
 * reserved for it, and only the leftover slot goes wandering.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Scorer {

	/** Per-request memo for the Polylang check in pick(). */
	private static array $lang_cache = [];

	/**
	 * @param array      $views    Validated views, each [post_id, duration_seconds, max_scroll_pct, last_seen].
	 * @param array      $searches Validated searches, each [query, source, timestamp].
	 * @param array|null $referral Validated referral, or null.
	 * @param array      $options  'limit' — slots to fill, default num_recommendations().
	 *                             'geo_levels' — narrow the geography to these levels.
	 * @return array{recommendations: array, debug: array, profile: array}
	 */
	public static function rank( int $current_post_id, string $lang, array $views, array $searches, ?array $referral, array $options = [] ): array {
		$debug = [
			'lang'            => $lang,
			'profile_views'   => [],
			'profile_filters' => [],
			'search_filters'  => [],
			'geo'             => [],
			'pool_size'       => 0,
			'candidates'      => [],
			'composition'     => [],
			'excluded'        => [],
		];

		$profile  = self::session_profile( $current_post_id, $lang, $views, $searches, $referral, $options );
		$ordered  = $profile['ordered'];
		$weighted = $profile['weighted'];

		$interest                 = $profile['interest'];
		$debug['profile_views']   = $profile['interest_debug'];
		$debug['profile_filters'] = $interest;

		$search_filters          = $profile['search_filters'];
		$debug['search_filters'] = $search_filters;

		$geo          = $profile['geo'];
		$debug['geo'] = self::describe_geo( $geo );

		$exclude = array_merge( [ $current_post_id ], array_column( $ordered, 'post_id' ) );

		// Hubs are gathered before the pool and placed before the ranking:
		// they are an editorial statement about what owns this content, not a
		// similarity score to be compared with one.
		$hub_context   = $profile['hub_context'];
		$hubs          = MFY_Hubs::session_hubs( $weighted, $lang, $exclude );
		$debug['hubs'] = self::describe_hubs( $hubs, $lang );

		$exclude = array_merge( $exclude, array_column( $hubs, 'post_id' ) );

		// The other children of those hubs: grouped by hand, so a better
		// guarantee of relevance than any score this plugin can compute.
		$hub_children = MFY_Hubs::child_candidates( $hub_context, $lang, $exclude );
		$candidates   = self::build_pool( $lang, $interest, $search_filters, $geo, $hub_children, $exclude, $debug );

		if ( ! $candidates && ! $hubs ) {
			return [ 'recommendations' => [], 'debug' => $debug, 'profile' => $profile ];
		}

		$candidate_ids  = array_column( $candidates, 'post_id' );
		$scores_by_post = MFY_Data::get_filter_scores_bulk( $candidate_ids, $lang );
		$places_by_post = MFY_Geo::places_for_posts( $candidate_ids, $lang );

		// Candidates sit in places the session has never visited, so their
		// names have to be resolved too before anything can be explained.
		$candidate_places = [];
		foreach ( $places_by_post as $map ) {
			$candidate_places = array_merge( $candidate_places, array_values( $map ) );
		}
		$geo['places'] = ( $geo['places'] ?? [] ) + MFY_Geo::place_names( $candidate_places, $lang );
		$min_score      = MFY_Config::min_score( [
			'lang'            => $lang,
			'current_post_id' => $current_post_id,
		] );

		$scored = [];
		foreach ( $candidates as $candidate ) {
			$post_id = $candidate['post_id'];
			$places  = $places_by_post[ $post_id ] ?? [];

			[ $total, $reasons, $matched_strong, $affinity ] = self::score_candidate(
				$scores_by_post[ $post_id ] ?? [],
				$interest,
				$search_filters,
				$places,
				$geo,
				$hub_children[ $post_id ] ?? null,
				$lang
			);

			$debug['candidates'][] = [
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
				'score'   => round( $total, 2 ),
				'place'   => self::place_label( $places, $geo ),
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
				'places'    => $places,
				'affinity'  => $affinity,
			];
		}

		usort( $scored, static function ( $a, $b ) {
			return [ $b['score'], $b['views'], $a['post_id'] ] <=> [ $a['score'], $a['views'], $b['post_id'] ];
		} );

		$limit  = max( 1, (int) ( $options['limit'] ?? MFY_Config::num_recommendations() ) );
		$picked = self::pick( $scored, $lang, $limit, $geo, $hubs, $debug );

		$recommendations = [];
		foreach ( $picked as $entry ) {
			$item = MFY_Data::format_item( $entry['post_id'] );
			if ( ! $item ) {
				continue;
			}
			$item['score'] = round( $entry['score'], 2 );

			if ( ! empty( $entry['hub_type'] ) ) {
				$item['hub'] = [
					'type'  => $entry['hub_type'],
					'label' => MFY_Hubs::label( $entry['hub_type'], $lang ),
				];
			}

			$recommendations[] = $item;
		}

		usort( $debug['candidates'], static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return [ 'recommendations' => $recommendations, 'debug' => $debug, 'profile' => $profile ];
	}

	/**
	 * Everything a session says about itself, before anything is ranked.
	 *
	 * rank() needs this to score three cards; the /pour-vous/ page needs the
	 * very same figures to build a row per signal instead of merging them all
	 * into one ordering. Computing it here rather than twice is what keeps the
	 * two surfaces honest: a session that is 70% London on the block is 70%
	 * London on the page, because it is literally the same number.
	 *
	 * @param array $options See rank().
	 * @return array{
	 *     ordered: array, weighted: array, interest: array<string,float>,
	 *     interest_debug: array, search_filters: array<string,float>,
	 *     geo: array, hub_context: array
	 * }
	 */
	public static function session_profile( int $current_post_id, string $lang, array $views, array $searches, ?array $referral, array $options = [] ): array {
		$ordered  = self::order_views( $current_post_id, $views );
		$weighted = self::weigh_views( $ordered );

		[ $interest, $interest_debug ] = self::build_interest_profile( $weighted, $lang );

		return [
			'ordered'        => $ordered,
			'weighted'       => $weighted,
			'interest'       => $interest,
			'interest_debug' => $interest_debug,
			'search_filters' => self::search_filters( $searches, $referral, $lang ),
			'geo'            => MFY_Geo::session_profile( $weighted, $lang, $options['geo_levels'] ?? null ),
			'hub_context'    => MFY_Hubs::session_context( $weighted, $lang ),
		];
	}

	/**
	 * The same ranking with no visitor in it: what this page relates to,
	 * judged only by the page itself.
	 *
	 * An impersonal block is a session of exactly one view — the current post,
	 * read attentively enough to count for a full unit of weight. Everything
	 * else follows: its hub is placed first, its place reserves the geography
	 * slots, its filters find the siblings. The two blocks cannot drift apart,
	 * because they are the same code with a different session.
	 *
	 * @param array $options See rank().
	 */
	public static function rank_for_post( int $post_id, string $lang, array $options = [] ): array {
		// Neutral engagement: 30s and 50% both sit in the 1.00 band, so the
		// synthetic view weighs exactly 1 and the numbers stay readable.
		$view = [
			'post_id'          => $post_id,
			'duration_seconds' => 30,
			'max_scroll_pct'   => 50,
			'last_seen'        => time(),
		];

		return self::rank( $post_id, $lang, [ $view ], [], null, $options );
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
	 * One weight per viewed post, recency × engagement.
	 *
	 * Computed once and handed to both profiles, so a post counts for exactly
	 * as much when it speaks about interests as when it speaks about places.
	 */
	private static function weigh_views( array $ordered_views ): array {
		$weights = array_values( MFY_Config::recency_weights() );
		$last    = end( $weights ) ?: 0.3;
		$out     = [];

		foreach ( array_values( $ordered_views ) as $index => $view ) {
			$recency    = $weights[ $index ] ?? $last;
			$engagement = self::engagement_multiplier( $view['duration_seconds'], $view['max_scroll_pct'] );

			$out[] = [
				'post_id'          => $view['post_id'],
				'recency'          => $recency,
				'engagement'       => $engagement,
				'weight'           => $recency * $engagement,
				'duration_seconds' => $view['duration_seconds'],
				'max_scroll_pct'   => $view['max_scroll_pct'],
			];
		}

		return $out;
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
	private static function build_interest_profile( array $weighted_views, string $lang ): array {
		$interest = [];
		$debug    = [];

		$scores_by_post = MFY_Data::get_filter_scores_bulk( array_column( $weighted_views, 'post_id' ), $lang );

		foreach ( $weighted_views as $view ) {
			$scores = $scores_by_post[ $view['post_id'] ] ?? [];
			$strong = [];

			foreach ( $scores as $slug => $score ) {
				if ( 2 !== (int) $score ) {
					continue;
				}
				$strong[] = $slug;
				$interest[ $slug ] = ( $interest[ $slug ] ?? 0.0 ) + $view['weight'];
			}

			$debug[] = [
				'post_id'          => $view['post_id'],
				'title'            => get_the_title( $view['post_id'] ),
				'recency'          => $view['recency'],
				'duration_seconds' => $view['duration_seconds'],
				'duration_mult'    => self::duration_multiplier( $view['duration_seconds'] ),
				'max_scroll_pct'   => $view['max_scroll_pct'],
				'scroll_mult'      => self::scroll_multiplier( $view['max_scroll_pct'] ),
				'engagement'       => round( $view['engagement'], 3 ),
				'weight'           => round( $view['weight'], 3 ),
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

		foreach ( $queries as [ $query, $weight ] ) {
			foreach ( self::query_filters( $query, $lang, $map ) as $slug ) {
				$out[ $slug ] = max( $out[ $slug ] ?? 0.0, (float) $weight );
			}
		}

		return $out;
	}

	/**
	 * The filter slugs one search string maps to.
	 *
	 * Split out of search_filters() because the /pour-vous/ page needs the
	 * mapping per query rather than merged: a row headed "parce que vous avez
	 * cherché « londres ado »" has to know which slugs *that* query produced,
	 * not which slugs the session's searches produced between them.
	 *
	 * @param array|null $map Pre-fetched dictionary, or null to look it up.
	 * @return string[]
	 */
	public static function query_filters( string $query, string $lang, ?array $map = null ): array {
		$normalized = self::normalize_query( $query );

		if ( '' === $normalized ) {
			return [];
		}

		$map          = null === $map ? MFY_Config::search_filter_map( $lang ) : $map;
		$signal_slugs = array_flip( MFY_Data::signal_slugs() );
		$out          = [];

		foreach ( $map as $token => $slug ) {
			if ( ! isset( $signal_slugs[ $slug ] ) || isset( $out[ $slug ] ) ) {
				continue;
			}
			// Prefix-at-word-boundary, so "rando" catches "randonnée" but
			// not "durando".
			if ( preg_match( '/\b' . preg_quote( self::normalize_query( $token ), '/' ) . '/u', $normalized ) ) {
				$out[ $slug ] = true;
			}
		}

		return array_keys( $out );
	}

	/** Lowercased, accent-folded, whitespace-collapsed. */
	private static function normalize_query( string $query ): string {
		$query = remove_accents( $query );
		$query = strtolower( $query );

		return trim( preg_replace( '/\s+/u', ' ', $query ) );
	}

	// -------------------------------------------------------------------------
	// Candidate pool
	// -------------------------------------------------------------------------

	/**
	 * The candidates worth scoring, from two sources merged.
	 *
	 * The filter pool answers "what else is this kind of trip?". The geography
	 * pool answers "what else is about this place?" — and it has to exist
	 * separately, because the next London article need not share a single
	 * strongly scored filter with the ones already read. The hub-children pool
	 * answers "what else did an editor put in here?". All three are bounded,
	 * and editorial eligibility (§10) applies to every row of all three; only
	 * the hub pages themselves bypass it.
	 */
	private static function build_pool( string $lang, array $interest, array $search_filters, array $geo, array $hub_children, array $exclude, array &$debug ): array {
		$pool       = [];
		$pool_slugs = array_values( array_unique( array_merge( array_keys( $interest ), array_keys( $search_filters ) ) ) );

		if ( $pool_slugs ) {
			foreach ( MFY_Data::get_candidates( $lang, $pool_slugs, $exclude, MFY_Config::candidate_pool_size() ) as $row ) {
				$pool[ $row['post_id'] ] = $row;
			}
		}

		$debug['pool_filter'] = count( $pool );

		$place_ids = [];
		foreach ( $geo['by_level'] ?? [] as $weights ) {
			$place_ids = array_merge( $place_ids, array_keys( $weights ) );
		}

		if ( $place_ids ) {
			$geo_ids = MFY_Geo::candidate_ids( $lang, $place_ids, $exclude, MFY_Config::geo_pool_size() );
			$added   = 0;

			foreach ( MFY_Data::filter_eligible( $lang, $geo_ids, $exclude ) as $row ) {
				if ( ! isset( $pool[ $row['post_id'] ] ) ) {
					$pool[ $row['post_id'] ] = $row;
					++$added;
				}
			}

			$debug['pool_geo'] = $added;
		}

		if ( $hub_children ) {
			$added = 0;

			foreach ( MFY_Data::filter_eligible( $lang, array_keys( $hub_children ), $exclude ) as $row ) {
				if ( ! isset( $pool[ $row['post_id'] ] ) ) {
					$pool[ $row['post_id'] ] = $row;
					++$added;
				}
			}

			$debug['pool_hub_children'] = $added;
		}

		if ( ! $pool ) {
			$debug['excluded'][] = $pool_slugs || $place_ids
				? 'no eligible candidates in either pool'
				: 'no strong session filters and no known geography — nothing to match on';
		}

		$debug['pool_size'] = count( $pool );

		return array_values( $pool );
	}

	// -------------------------------------------------------------------------
	// Candidate scoring
	// -------------------------------------------------------------------------

	/**
	 * @return array{0: float, 1: string[], 2: string[], 3: ?array} score, reasons, matched strong slugs, geo affinity.
	 */
	private static function score_candidate( array $scores, array $interest, array $search_filters, array $places, array $geo, ?array $hub_relationship, string $lang ): array {
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

		$affinity = MFY_Geo::affinity( $places, $geo );

		if ( $affinity ) {
			$total += $affinity['points'];
			$reasons[] = sprintf(
				'+%.2f %s (same %s as this session)',
				$affinity['points'],
				$geo['places'][ $affinity['place_id'] ] ?? ( '#' . $affinity['place_id'] ),
				$affinity['level']
			);
		}

		if ( $hub_relationship ) {
			$points = MFY_Hubs::child_points( $hub_relationship );
			$total += $points;
			$reasons[] = sprintf(
				'+%.2f %s (%s this session is reading in)',
				$points,
				get_the_title( $hub_relationship['hub_id'] ),
				strtolower( MFY_Hubs::label( $hub_relationship['type'], $lang ) ?: 'hub' )
			);
		}

		sort( $strong );

		return [ $total, $reasons, $strong, $affinity ];
	}

	// -------------------------------------------------------------------------
	// Composition
	// -------------------------------------------------------------------------

	/**
	 * Which candidates actually get shown, and in what mix.
	 *
	 * When the session is focused on one place, most of the slots are reserved
	 * for it and filled by score — the reader gets more of where they are, and
	 * the remaining slot deliberately goes somewhere else so the block still
	 * offers a way out. When there is no focus, this is a plain top-N with the
	 * diversity pass, exactly as before geography existed.
	 *
	 * Reservation walks the focus chain outwards (city → region → country) and
	 * settles on the most specific level that can actually fill the reserved
	 * slots: two unread London articles reserve London; one unread London
	 * article reserves Greater London, or England, rather than showing a lone
	 * London card and calling it a theme.
	 */
	private static function pick( array $scored, string $lang, int $limit, array $geo, array $hubs, array &$debug ): array {
		$picked = self::place_hubs( $hubs, $limit, $debug );

		// Whatever the hub did not take is what geography gets to divide up:
		// with three slots and a hub placed, the focused place reserves one
		// and one still goes elsewhere.
		$remaining = $limit - count( $picked );
		$chain     = $geo['chain'] ?? [];
		$reserved  = $chain ? MFY_Config::geo_reserved_slots( $remaining ) : 0;
		$focus     = null;

		if ( $reserved > 0 ) {
			$best = [];

			foreach ( $chain as $link ) {
				$matches = array_values( array_filter(
					$scored,
					static fn( $entry ) => (int) ( $entry['places'][ $link['level'] ] ?? 0 ) === $link['place_id']
				) );
				$matches = array_values( array_filter( $matches, static fn( $entry ) => self::lang_ok( $entry['post_id'], $lang ) ) );

				if ( count( $matches ) > count( $best ) ) {
					$best  = $matches;
					$focus = $link;
				}
				if ( count( $matches ) >= $reserved ) {
					$best  = $matches;
					$focus = $link;
					break; // Most specific level that can fill the quota wins.
				}
			}

			$picked = array_merge( $picked, array_slice( $best, 0, $reserved ) );

			$debug['composition'] = array_merge( $debug['composition'] ?? [], [
				'focus_level'    => $focus['level'] ?? null,
				'focus_place'    => $focus ? ( $geo['places'][ $focus['place_id'] ] ?? $focus['place_id'] ) : null,
				'focus_share'    => $focus['share'] ?? null,
				'slots_reserved' => $reserved,
				'slots_filled'   => count( $picked ) - ( $debug['composition']['slots_hub'] ?? 0 ),
				'available'      => count( $best ),
			] );
		}

		$taken = array_column( $picked, 'post_id' );

		// The remaining slots go elsewhere: the best-scoring candidates that
		// are *not* in the focused place, so three London articles cannot turn
		// the whole block into London.
		$picked = self::fill( $picked, $scored, $lang, $limit, $focus, true, $debug );

		// Only if there is nowhere else worth going does the focus fill the
		// rest — a slightly repetitive card still beats an empty slot.
		$picked = self::fill( $picked, $scored, $lang, $limit, $focus, false, $debug );

		if ( $reserved > 0 ) {
			$debug['composition']['slots_elsewhere'] = count( $picked ) - count( $taken );
		}

		return $picked;
	}

	/**
	 * The hubs that get a slot, ahead of everything else.
	 *
	 * They are not ranked against candidates and no minimum score applies: a
	 * hub earns its place by being the page an editor said owns what this
	 * visitor has been reading. Already-read hubs never reach here — they are
	 * excluded upstream, and surfaced under "recently viewed" instead.
	 */
	private static function place_hubs( array $hubs, int $limit, array &$debug ): array {
		$max    = min( MFY_Config::max_hub_recommendations(), $limit );
		$picked = [];

		foreach ( $hubs as $hub ) {
			if ( count( $picked ) >= $max ) {
				break;
			}

			$picked[] = [
				'post_id'   => $hub['post_id'],
				'score'     => MFY_Hubs::score( $hub ),
				'views'     => 0,
				'signature' => [],
				'places'    => [],
				'affinity'  => null,
				'hub_type'  => $hub['type'],
			];
		}

		if ( $picked ) {
			$debug['composition']['slots_hub'] = count( $picked );
			$debug['composition']['hubs']      = array_column( $picked, 'post_id' );
		}

		return $picked;
	}

	/**
	 * Adds candidates until the block is full.
	 *
	 * @param array|null $focus       The focused chain link, or null when unfocused.
	 * @param bool       $avoid_focus Skip candidates sitting in the focused place.
	 */
	private static function fill( array $picked, array $scored, string $lang, int $limit, ?array $focus, bool $avoid_focus, array &$debug ): array {
		if ( count( $picked ) >= $limit ) {
			return $picked;
		}

		$taken      = array_flip( array_column( $picked, 'post_id' ) );
		$signatures = [];
		$deferred   = [];

		// The diversity pass applies to discovery picks only. Reserved picks
		// are supposed to look alike — that is the whole point of a focus —
		// so their signatures are not registered as "already seen".
		foreach ( $scored as $entry ) {
			if ( count( $picked ) >= $limit ) {
				break;
			}
			if ( isset( $taken[ $entry['post_id'] ] ) ) {
				continue;
			}

			$in_focus = $focus && (int) ( $entry['places'][ $focus['level'] ] ?? 0 ) === $focus['place_id'];
			if ( $avoid_focus && $in_focus ) {
				continue;
			}

			// Language is enforced by the pool queries via the tvf lang column
			// and the per-language place terms; this re-checks the handful of
			// posts actually about to be shown against Polylang itself.
			if ( ! self::lang_ok( $entry['post_id'], $lang ) ) {
				$debug['excluded'][] = sprintf( '%d language mismatch', $entry['post_id'] );
				continue;
			}

			$signature = implode( '|', $entry['signature'] );
			if ( '' !== $signature && isset( $signatures[ $signature ] ) ) {
				$deferred[] = $entry;
				$debug['excluded'][] = sprintf( '%d deferred by diversity pass (same strong profile: %s)', $entry['post_id'], $signature );
				continue;
			}

			$signatures[ $signature ] = true;
			$taken[ $entry['post_id'] ] = true;
			$picked[] = $entry;
		}

		foreach ( $deferred as $entry ) {
			if ( count( $picked ) >= $limit ) {
				break;
			}
			$taken[ $entry['post_id'] ] = true;
			$picked[] = $entry;
		}

		return $picked;
	}

	/** Memoized Polylang check — pick() may test the same post more than once. */
	private static function lang_ok( int $post_id, string $lang ): bool {
		$key = $post_id . ':' . $lang;

		if ( ! isset( self::$lang_cache[ $key ] ) ) {
			self::$lang_cache[ $key ] = ( MFY_Data::post_lang( $post_id ) === $lang );
		}

		return self::$lang_cache[ $key ];
	}

	// -------------------------------------------------------------------------
	// Debug helpers
	// -------------------------------------------------------------------------

	/** The session geography, in names rather than place IDs. */
	private static function describe_geo( array $geo ): array {
		if ( empty( $geo['by_level'] ) ) {
			return [ 'available' => MFY_Geo::available(), 'focus' => null, 'levels' => [] ];
		}

		$levels = [];
		foreach ( $geo['by_level'] as $level => $weights ) {
			foreach ( $weights as $place_id => $weight ) {
				$levels[ $level ][ $geo['places'][ $place_id ] ?? ( '#' . $place_id ) ] = round( $weight, 3 );
			}
		}

		$focus = [];
		foreach ( $geo['chain'] as $link ) {
			$focus[] = sprintf(
				'%s: %s (%.0f%% of this session)',
				$link['level'],
				$geo['places'][ $link['place_id'] ] ?? ( '#' . $link['place_id'] ),
				$link['share'] * 100
			);
		}

		return [
			'available' => true,
			'levels'    => $levels,
			'focus'     => $focus,
		];
	}

	/** The session's hubs, in names rather than IDs. */
	private static function describe_hubs( array $hubs, string $lang ): array {
		if ( ! MFY_Hubs::available() ) {
			return [ 'available' => false, 'found' => [] ];
		}

		$found = [];
		foreach ( $hubs as $hub ) {
			$found[] = sprintf(
				'%s — %s (%s, weight %.2f, from %d viewed article%s)',
				get_the_title( $hub['post_id'] ),
				MFY_Hubs::label( $hub['type'], $lang ),
				$hub['type'],
				$hub['weight'],
				count( $hub['sources'] ),
				count( $hub['sources'] ) === 1 ? '' : 's'
			);
		}

		return [ 'available' => true, 'found' => $found ];
	}

	/** A candidate's most specific known place, for the debug table. */
	private static function place_label( array $places, array $geo ): string {
		foreach ( MFY_Geo::levels() as $level ) {
			if ( ! empty( $places[ $level ] ) ) {
				return (string) ( $geo['places'][ $places[ $level ] ] ?? ( '#' . $places[ $level ] ) );
			}
		}

		return '—';
	}
}

<?php
/**
 * The boundary between this plugin and the editorial hub model, alongside
 * MFY_Data (filter scores) and MFY_Geo (places). Nothing here reads hub meta
 * directly — Hub Manager's helper API is the contract, exactly as its README
 * asks.
 *
 * A hub is not another similarity signal. It is an editorial statement that
 * *this page owns this content*, made by hand. Someone three articles into
 * London has been reading the children of a London hub, and the most useful
 * thing to offer them is the hub itself: the page that gathers everything
 * else. So hubs are not scored against candidates — they are placed first,
 * ahead of the ranking, and the ranking fills what is left.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Hubs {

	/** Hub Manager's two relationship types, in tie-break order. */
	const TYPES = [ 'geo', 'theme' ];

	/** Per-request memo of validated hub types, keyed post ID. */
	private static array $type_cache = [];

	/**
	 * Is the hub model reachable?
	 *
	 * When Hub Manager is not active every hub feature switches off and the
	 * ranking is exactly what it was before hubs existed.
	 */
	public static function available(): bool {
		return function_exists( 'mavo_get_hub_ancestors' ) && function_exists( 'mavo_get_hub_type' );
	}

	/**
	 * The hub type of a post, or null — validated, not merely read.
	 *
	 * Hub Manager stores relationships as raw meta and says plainly that it
	 * "may be stale; validate before use". A hub that has been unpublished or
	 * whose type was changed must not reach a reader.
	 */
	public static function hub_type( int $post_id ): ?string {
		if ( array_key_exists( $post_id, self::$type_cache ) ) {
			return self::$type_cache[ $post_id ];
		}

		$type = null;

		if ( self::available() ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post
				&& 'publish' === $post->post_status
				&& in_array( $post->post_type, MFY_Config::trackable_post_types(), true ) ) {
				$candidate = mavo_get_hub_type( $post_id );
				$type      = in_array( $candidate, self::TYPES, true ) ? $candidate : null;
			}
		}

		return self::$type_cache[ $post_id ] = $type;
	}

	public static function is_hub( int $post_id ): bool {
		return null !== self::hub_type( $post_id );
	}

	/**
	 * The hubs this session has been reading inside, most relevant first.
	 *
	 * Each viewed post contributes its own weight (recency × engagement, the
	 * same figure the filter and geography profiles use) to its immediate
	 * primary hub, and a decayed share to that hub's own parent — someone who
	 * read three Paris articles is mostly signalling Paris, and faintly
	 * signalling France.
	 *
	 * Both hierarchies are walked independently, so a post can point at a
	 * geographic hub and a thematic one at once.
	 *
	 * A hub the visitor has already read stays in this list: it is no longer
	 * something to suggest, but it is still the context they are reading in,
	 * and its other children are exactly what they have not seen yet. Callers
	 * that offer hubs as suggestions filter it through session_hubs().
	 *
	 * @param array $weighted_views [ [ 'post_id' => int, 'weight' => float ], … ]
	 * @return array<int, array{post_id:int, type:string, weight:float, depth:int, sources:int[]}>
	 */
	public static function session_context( array $weighted_views, string $lang ): array {
		if ( ! $weighted_views || ! self::available() ) {
			return [];
		}

		$decay     = MFY_Config::hub_ancestor_decay();
		$max_depth = MFY_Config::hub_max_depth();
		$found     = [];

		$add = static function ( int $hub_id, string $type, float $weight, int $depth, int $source ) use ( &$found, $lang ) {
			if ( self::hub_type( $hub_id ) !== $type ) {
				return; // Unpublished, retyped, or never a hub.
			}
			if ( MFY_Data::post_lang( $hub_id ) !== $lang ) {
				return; // Never cross languages, not even for a hub.
			}

			$key = $type . ':' . $hub_id;

			if ( ! isset( $found[ $key ] ) ) {
				$found[ $key ] = [
					'post_id' => $hub_id,
					'type'    => $type,
					'weight'  => 0.0,
					'depth'   => $depth,
					'sources' => [],
				];
			}

			$found[ $key ]['weight']   += $weight;
			$found[ $key ]['depth']     = min( $found[ $key ]['depth'], $depth );
			$found[ $key ]['sources'][] = $source;
		};

		foreach ( $weighted_views as $view ) {
			$post_id = (int) $view['post_id'];
			$weight  = (float) $view['weight'];

			// Reading a hub page is the strongest possible statement of
			// context — the visitor is standing in it.
			$own_type = self::hub_type( $post_id );
			if ( $own_type ) {
				$add( $post_id, $own_type, $weight, 0, $post_id );
			}

			foreach ( self::TYPES as $type ) {
				$chain = mavo_get_hub_ancestors( $post_id, $type );

				foreach ( array_slice( $chain, 0, $max_depth ) as $depth => $hub_id ) {
					$add( absint( $hub_id ), $type, $weight * ( $decay ** $depth ), $depth, $post_id );
				}
			}
		}

		return self::sorted( $found );
	}

	/**
	 * The hubs worth offering as suggestions: the session's context, minus
	 * anything already read this visit. An already-read hub is not news; it is
	 * surfaced under "recently viewed" instead, where it is a way back.
	 *
	 * @param int[] $exclude Current post + everything already viewed.
	 */
	public static function session_hubs( array $weighted_views, string $lang, array $exclude ): array {
		$excluded = array_flip( array_map( 'absint', $exclude ) );

		return array_values( array_filter(
			self::session_context( $weighted_views, $lang ),
			static fn( $hub ) => ! isset( $excluded[ $hub['post_id'] ] )
		) );
	}

	/**
	 * Unread articles belonging to the hubs this session is reading in.
	 *
	 * The sibling case: someone on a London hub page, or three articles into
	 * it, has not seen its other children — and those are editorially grouped
	 * by hand, which is a better guarantee of relevance than any score. They
	 * enter the candidate pool like anything else and are still subject to
	 * §10 eligibility; only the hubs themselves bypass that.
	 *
	 * Bounded twice over: the strongest few hubs are expanded, each by a
	 * capped query.
	 *
	 * @param array $context From session_context().
	 * @param int[] $exclude Current post + everything already viewed.
	 * @return array<int, array{hub_id:int, type:string, weight:float}> Keyed by child post ID.
	 */
	public static function child_candidates( array $context, string $lang, array $exclude ): array {
		if ( ! $context || ! self::available() ) {
			return [];
		}

		$excluded = array_flip( array_map( 'absint', $exclude ) );
		$hubs     = array_slice( $context, 0, MFY_Config::hub_child_hubs() );
		$limit    = MFY_Config::hub_child_pool_size();
		$out      = [];

		foreach ( $hubs as $hub ) {
			$children = mavo_get_hub_children( $hub['post_id'], $hub['type'], [
				// The helper defaults to post_status "any"; a draft child must
				// never reach a reader.
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
			] );

			foreach ( $children as $child_id ) {
				$child_id = absint( $child_id );

				if ( ! $child_id || isset( $excluded[ $child_id ] ) ) {
					continue;
				}
				if ( MFY_Data::post_lang( $child_id ) !== $lang ) {
					continue;
				}
				// A stronger hub relationship replaces a weaker one: a child
				// counts once, for the hub that best explains it.
				if ( isset( $out[ $child_id ] ) && $out[ $child_id ]['weight'] >= $hub['weight'] ) {
					continue;
				}

				$out[ $child_id ] = [
					'hub_id' => $hub['post_id'],
					'type'   => $hub['type'],
					'weight' => (float) $hub['weight'],
				];
			}
		}

		return $out;
	}

	/** Most weight first, geo before theme on a tie, then post ID. */
	private static function sorted( array $found ): array {
		$hubs = array_values( $found );

		usort( $hubs, static function ( $a, $b ) {
			return [ $b['weight'], 'geo' === $b['type'] ? 1 : 0, $a['post_id'] ]
				<=> [ $a['weight'], 'geo' === $a['type'] ? 1 : 0, $b['post_id'] ];
		} );

		foreach ( $hubs as &$hub ) {
			$hub['weight']  = round( $hub['weight'], 4 );
			$hub['sources'] = array_values( array_unique( $hub['sources'] ) );
		}
		unset( $hub );

		return $hubs;
	}

	/**
	 * The score a hub is reported with.
	 *
	 * Hubs are placed by composition rather than by ranking, so this number
	 * never decides anything — it exists so debug output and the response can
	 * show the hub on the same scale as everything else.
	 */
	public static function score( array $hub ): float {
		return MFY_Config::hub_points() * (float) $hub['weight'];
	}

	/**
	 * Points an unread article earns for belonging to a hub this session is
	 * reading in, scaled by that hub's session weight.
	 *
	 * Sized so that a child of the hub on the current page clears the minimum
	 * score on this relationship alone: being hand-placed in the same hub is a
	 * stronger statement than any two filters happening to coincide.
	 */
	public static function child_points( array $relationship ): float {
		return MFY_Config::hub_child_points() * (float) $relationship['weight'];
	}

	/**
	 * The word shown to the reader above a hub's title.
	 *
	 * Not "hub": that is the internal name for the relationship, not something
	 * a visitor should ever have to parse. See MFY_Config::hub_labels().
	 */
	public static function label( string $type, string $lang ): string {
		$labels = MFY_Config::hub_labels( $lang );

		return (string) ( $labels[ $type ] ?? '' );
	}
}

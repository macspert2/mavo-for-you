<?php
/**
 * The /pour-vous/ page: one row per reason, instead of one ranking.
 *
 * The "Pour vous" block answers a hard question — of everything on the site,
 * which three articles does this reader most want next? — by merging every
 * signal into a single number and taking the top of it. That merge is what
 * makes three cards possible, and it is also what throws information away: the
 * reader never learns that two of the three are London articles and the third
 * came from a search they ran.
 *
 * The page takes the other option. Each signal the session produces becomes a
 * heading of its own, filled with the articles that signal alone would pick,
 * and the reader sees the shape of their own visit laid out: the guide they
 * have been reading in, the places they keep returning to, the words they
 * typed, the themes that recur. It is the same session profile, unmerged.
 *
 * Two consequences follow, both deliberate:
 *
 *   - An article may appear in several rows. That is not duplication, it is
 *     the point: an article can be both "in the London guide" and "a city
 *     break with teenagers", and hiding one of those facts to avoid repeating
 *     a thumbnail would make the headings lie. Within a row, of course, once.
 *   - Already-read articles are shown rather than excluded. The block is about
 *     what is next; the page is about the territory, and a map with the roads
 *     you have already driven erased is not a map. They carry the same
 *     data-mavo-post-id every other tile does, so the existing read-marking
 *     pass dims them without this code knowing anything about it.
 *
 * Rows are assembled as bare post IDs and hydrated separately, because the
 * block needs to know *how many* rows a session could fill — to decide whether
 * to link here at all — without paying for a hundred post objects to find out.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Rows {

	/**
	 * Row kinds in the order they appear on the page.
	 *
	 * Search first: a query is the visitor stating in their own words what
	 * they came for, and nothing this plugin infers outranks that. Then hubs —
	 * an editor's statement about what belongs together. Then geography, which
	 * is the strongest thing the numbers know. Themes last, because a shared
	 * filter is the most abstract of the four. "Recently viewed" is appended
	 * after everything, by hand.
	 */
	const KIND_ORDER = [ 'search', 'hub', 'geo', 'filter' ];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * The page, ready to render.
	 *
	 * @param array      $views    Validated views, newest first is not assumed.
	 * @param array      $searches Validated searches.
	 * @param array|null $referral Validated referral, or null.
	 * @return array{rows: array, personalized: bool, debug: array}
	 */
	public static function build( string $lang, array $views, array $searches, ?array $referral ): array {
		if ( ! $views ) {
			return self::fallback( $lang );
		}

		$profile = MFY_Scorer::session_profile( 0, $lang, $views, $searches, $referral );
		$rows    = self::assemble( $lang, $profile, $views, $searches );

		// A session that produced nothing — every row too thin, or the site
		// has no eligible material in this language — still gets a page rather
		// than a blank one, with the fallback's own honest framing.
		if ( ! self::has_suggestions( $rows ) ) {
			$fallback         = self::fallback( $lang );
			$fallback['rows'] = array_merge( $fallback['rows'], self::hydrate( self::recent_rows( $rows ) ) );

			return $fallback;
		}

		return [
			'rows'         => self::hydrate( $rows ),
			'personalized' => true,
			'debug'        => self::describe( $rows ),
		];
	}

	/**
	 * How many rows of real substance this session could fill.
	 *
	 * Called from the "Pour vous" block to decide whether to offer a link to
	 * the page, so it does the assembly and stops: no post objects, no
	 * thumbnails, no excerpts. The "recently viewed" row does not count — it
	 * is the reader's own history, not a reason to visit a suggestions page.
	 *
	 * @param array|null $profile A profile already computed by the caller.
	 */
	public static function count_available( string $lang, array $views, array $searches, ?array $referral, ?array $profile = null ): int {
		if ( ! $views ) {
			return 0;
		}

		$profile = $profile ?? MFY_Scorer::session_profile( 0, $lang, $views, $searches, $referral );

		return count( self::assemble( $lang, $profile, $views, $searches, true ) );
	}

	// -------------------------------------------------------------------------
	// Assembly
	// -------------------------------------------------------------------------

	/**
	 * Every row this session earns, as post IDs.
	 *
	 * @param bool $suggestions_only Skip the "recently viewed" row.
	 * @return array<int, array{kind:string, key:string, title:string, source:array, ids:int[]}>
	 */
	private static function assemble( string $lang, array $profile, array $views, array $searches, bool $suggestions_only = false ): array {
		$labels = MFY_Config::page_labels( $lang );
		$caps   = MFY_Config::page_rows_per_kind();

		// Geography is built before the hubs even though it is shown after
		// them: a hub row that names the same place as a surviving geography
		// row is a duplicate heading and stands down for it. Only the geo rows
		// that actually made the cut can suppress anything.
		$geo_rows = self::geo_rows( $lang, $profile['geo'], $labels, (int) ( $caps['geo'] ?? 4 ) );
		$hub_rows = self::hub_rows( $lang, $profile['hub_context'], $labels, (int) ( $caps['hub'] ?? 3 ) );

		$by_kind = [
			'search' => self::search_rows( $lang, $searches, $labels, (int) ( $caps['search'] ?? 2 ) ),
			'hub'    => self::drop_hubs_named_by_place( $hub_rows, $geo_rows ),
			'geo'    => $geo_rows,
			'filter' => self::filter_rows( $lang, $profile['interest'], $labels, (int) ( $caps['filter'] ?? 5 ) ),
		];

		$rows = [];
		foreach ( self::KIND_ORDER as $kind ) {
			$rows = array_merge( $rows, $by_kind[ $kind ] );
		}

		$rows = self::drop_redundant( $rows );
		$rows = array_slice( $rows, 0, max( 1, MFY_Config::page_max_rows() ) );

		if ( ! $suggestions_only ) {
			$rows = array_merge( $rows, self::recent_row( $lang, $views, $labels ) );
		}

		return $rows;
	}

	/**
	 * One row per search the visitor ran, filled by what that query means.
	 *
	 * Only queries the dictionary recognizes produce a row: a search for
	 * "londres ado" maps to filter slugs and becomes a row, a search for a
	 * misspelling maps to nothing and is silently skipped. V0 does not guess.
	 */
	private static function search_rows( string $lang, array $searches, array $labels, int $cap ): array {
		if ( $cap < 1 ) {
			return [];
		}

		// Newest first: the most recent thing someone typed is what they are
		// still looking for.
		usort( $searches, static fn( $a, $b ) => ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 ) );

		$map  = MFY_Config::search_filter_map( $lang );
		$seen = [];
		$rows = [];

		foreach ( $searches as $search ) {
			if ( count( $rows ) >= $cap ) {
				break;
			}

			$query = (string) ( $search['query'] ?? '' );
			$key   = mb_strtolower( $query );

			if ( '' === $query || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$slugs = MFY_Scorer::query_filters( $query, $lang, $map );
			if ( ! $slugs ) {
				continue;
			}

			// Ordered by how many of the query's slugs a post scores 2 on, so
			// "londres ado" puts the articles that are both first.
			$row = self::row(
				'search',
				'search:' . $key,
				sprintf( $labels['rowSearch'], $query ),
				[ 'query' => $query, 'filters' => $slugs ],
				array_column( MFY_Data::get_candidates( $lang, $slugs, [], self::row_size() ), 'post_id' )
			);

			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * One row per hub the session has been reading inside, its other children.
	 *
	 * Hand-grouped by an editor, so this is the row least likely to need
	 * explaining and the one most likely to be useful — which is why it sits
	 * near the top of the page.
	 */
	private static function hub_rows( string $lang, array $hub_context, array $labels, int $cap ): array {
		if ( $cap < 1 || ! $hub_context ) {
			return [];
		}

		$rows = [];

		foreach ( $hub_context as $hub ) {
			if ( count( $rows ) >= $cap ) {
				break;
			}

			$hub_id   = (int) $hub['post_id'];
			$children = MFY_Hubs::children( $hub_id, (string) $hub['type'], self::row_size() + MFY_Config::pool_overfetch() );

			if ( ! $children ) {
				continue;
			}

			$title = get_the_title( $hub_id );
			if ( '' === trim( (string) $title ) ) {
				continue;
			}

			$title = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );

			$row = self::row(
				'hub',
				'hub:' . $hub_id,
				sprintf( $labels['rowHub'], $title ),
				[
					'hub_id'   => $hub_id,
					'type'     => $hub['type'],
					'label'    => MFY_Hubs::label( (string) $hub['type'], $lang ),
					'url'      => (string) get_permalink( $hub_id ),
					'title'    => $title,
				],
				// The hub's own order is editorial; eligibility only filters it.
				self::eligible( $lang, $children )
			);

			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Drops a hub row whose title *is* the name of a place a geography row
	 * already shows.
	 *
	 * Rows are allowed to repeat articles — that is the point of the page —
	 * but not to repeat a heading. "Dans « Angleterre »" directly above
	 * "Destination Angleterre" is one heading said twice, and no reader will
	 * take it for two ways of looking at their session.
	 *
	 * The geography row wins, because it is the more complete answer: it holds
	 * every article tagged with the place, which in practice includes the
	 * hub's children and more besides. What is lost is the hub's editorial
	 * ordering, which is a smaller thing than a duplicated heading.
	 *
	 * The match is on the words, and only when they are the *whole* title. An
	 * earlier version compared the hub's own geo tag with the row's place
	 * instead, which is a more principled notion of "the same subject" and a
	 * worse rule to live with: it also swallowed "Londres en famille" the
	 * moment a "Destination Londres" row appeared, and a curated guide with a
	 * name of its own is not the same promise as everything tagged with a
	 * city. Two headings that read differently are allowed to coexist even
	 * when they are about the same place.
	 *
	 * So: exact title, after lowercasing and folding accents. "Angleterre"
	 * stands down; "Angleterre en famille" does not. Thematic hubs are never
	 * suppressed — a theme is not a destination, whatever it is called.
	 */
	private static function drop_hubs_named_by_place( array $hub_rows, array $geo_rows ): array {
		if ( ! $hub_rows || ! $geo_rows ) {
			return $hub_rows;
		}

		$names = [];

		foreach ( $geo_rows as $row ) {
			$name = self::normalize( (string) ( $row['source']['place'] ?? '' ) );

			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		return array_values( array_filter( $hub_rows, static function ( $row ) use ( $names ) {
			if ( 'geo' !== ( $row['source']['type'] ?? '' ) ) {
				return true;
			}

			$title = self::normalize( (string) ( $row['source']['title'] ?? '' ) );

			return '' === $title || ! isset( $names[ $title ] );
		} ) );
	}

	/** Lowercased, accent-folded, whitespace-collapsed — for comparing names. */
	private static function normalize( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', strtolower( remove_accents( $text ) ) ) );
	}

	/**
	 * One row per place the session keeps coming back to, most specific first.
	 *
	 * Unlike the block, this does not wait for a *focused* session: two London
	 * articles and one Barcelona article are two places worth a row each, and
	 * a page has room to say so where three cards do not.
	 */
	private static function geo_rows( string $lang, array $geo, array $labels, int $cap ): array {
		if ( $cap < 1 || empty( $geo['by_level'] ) ) {
			return [];
		}

		$per_level = max( 1, MFY_Config::page_geo_places_per_level() );
		$wanted    = [];

		foreach ( ( $geo['levels'] ?? MFY_Geo::levels() ) as $level ) {
			$weights = $geo['by_level'][ $level ] ?? [];

			// session_profile() already sorted these by weight.
			foreach ( array_slice( array_keys( $weights ), 0, $per_level ) as $place_id ) {
				$wanted[] = [ 'level' => $level, 'place_id' => (int) $place_id ];
			}
		}

		$names = MFY_Geo::place_names( array_column( $wanted, 'place_id' ), $lang ) + ( $geo['places'] ?? [] );
		$rows  = [];

		foreach ( $wanted as $place ) {
			if ( count( $rows ) >= $cap ) {
				break;
			}

			$name = (string) ( $names[ $place['place_id'] ] ?? '' );
			if ( '' === $name ) {
				continue; // A place with no name in this language is not a heading.
			}

			$ids = MFY_Geo::candidate_ids( $lang, [ $place['place_id'] ], [], self::row_size() + MFY_Config::pool_overfetch() );

			$row = self::row(
				'geo',
				'geo:' . $place['level'] . ':' . $place['place_id'],
				sprintf( $labels['rowGeo'], $name ),
				[ 'level' => $place['level'], 'place_id' => $place['place_id'], 'place' => $name ],
				self::eligible( $lang, $ids )
			);

			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * One row per theme the session keeps scoring 2 on.
	 *
	 * The most abstract of the four signals and the last on the page, but the
	 * one that reaches furthest: it is what carries a reader from London to
	 * Copenhagen because both are city breaks that work with teenagers.
	 */
	private static function filter_rows( string $lang, array $interest, array $labels, int $cap ): array {
		if ( $cap < 1 || ! $interest ) {
			return [];
		}

		$names = MFY_Data::signal_labels( $lang );
		$rows  = [];

		// build_interest_profile() returns these weight-descending.
		foreach ( array_keys( $interest ) as $slug ) {
			if ( count( $rows ) >= $cap ) {
				break;
			}

			$name = (string) ( $names[ $slug ] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$row = self::row(
				'filter',
				'filter:' . $slug,
				sprintf( $labels['rowFilter'], $name ),
				[ 'filter' => $slug, 'label' => $name, 'weight' => round( (float) $interest[ $slug ], 3 ) ],
				array_column( MFY_Data::get_candidates( $lang, [ $slug ], [], self::row_size() ), 'post_id' )
			);

			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * The visitor's own history, last on the page.
	 *
	 * The one row exempt from the four-tile minimum: it promises no category,
	 * so two entries are not a broken promise, and it is the only row on the
	 * page the reader can verify at a glance. It simply does not rotate when
	 * it is short.
	 */
	private static function recent_row( string $lang, array $views, array $labels ): array {
		usort( $views, static fn( $a, $b ) => ( $b['last_seen'] ?? 0 ) <=> ( $a['last_seen'] ?? 0 ) );

		$ids = array_slice( array_column( $views, 'post_id' ), 0, self::row_size() );

		if ( count( $ids ) < 2 ) {
			return [];
		}

		return [ [
			'kind'   => 'recent',
			'key'    => 'recent',
			'title'  => (string) ( $labels['rowRecent'] ?? '' ),
			'source' => [],
			'ids'    => array_map( 'absint', $ids ),
		] ];
	}

	/** The "recently viewed" row out of an assembled set, if it is there. */
	private static function recent_rows( array $rows ): array {
		return array_values( array_filter( $rows, static fn( $row ) => 'recent' === $row['kind'] ) );
	}

	private static function has_suggestions( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( 'recent' !== $row['kind'] ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Cold visits
	// -------------------------------------------------------------------------

	/**
	 * The page someone gets with no history at all: a shared link, a crawler,
	 * a first visit.
	 *
	 * Not personalization and the copy says so. What it shows instead is the
	 * catalogue's own answer — the most-read articles under a few broad
	 * filters — which is honest, useful, crawlable, and identical for
	 * everybody, so it is the one part of this page that may be cached.
	 */
	private static function fallback( string $lang ): array {
		$key    = MFY_Cache::key( 'pagefall', [ $lang ] );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return [ 'rows' => $cached, 'personalized' => false, 'debug' => [ 'fallback' => 'cached' ] ];
		}

		$labels = MFY_Config::page_labels( $lang );
		$names  = MFY_Data::signal_labels( $lang );
		$slugs  = MFY_Config::page_fallback_filters();

		// Unconfigured: the first few signal slugs the registry offers, which
		// on this site are the "intérêt" filters — broad by construction.
		if ( ! $slugs ) {
			$slugs = array_slice( MFY_Data::signal_slugs(), 0, MFY_Config::page_fallback_rows() * 2 );
		}

		$rows = [];

		foreach ( $slugs as $slug ) {
			if ( count( $rows ) >= MFY_Config::page_fallback_rows() ) {
				break;
			}

			$name = (string) ( $names[ $slug ] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$row = self::row(
				'fallback',
				'fallback:' . $slug,
				sprintf( $labels['rowFilter'], $name ),
				[ 'filter' => $slug, 'label' => $name ],
				array_column( MFY_Data::get_candidates( $lang, [ $slug ], [], self::row_size() ), 'post_id' )
			);

			if ( $row ) {
				$rows[] = $row;
			}
		}

		$rows = self::hydrate( self::drop_redundant( $rows ) );

		set_transient( $key, $rows, MFY_Config::page_fallback_cache_ttl() );

		return [ 'rows' => $rows, 'personalized' => false, 'debug' => [ 'fallback' => 'built' ] ];
	}

	// -------------------------------------------------------------------------
	// Row plumbing
	// -------------------------------------------------------------------------

	/**
	 * One row, or null if it is too thin to be worth a heading.
	 *
	 * @param int[] $ids
	 */
	private static function row( string $kind, string $key, string $title, array $source, array $ids ): ?array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$ids = array_slice( $ids, 0, self::row_size() );

		if ( count( $ids ) < MFY_Config::page_row_min() || '' === trim( $title ) ) {
			return null;
		}

		return [
			'kind'   => $kind,
			'key'    => $key,
			'title'  => $title,
			'source' => $source,
			'ids'    => $ids,
		];
	}

	/**
	 * §10 eligibility, applied without losing the caller's ordering.
	 *
	 * MFY_Data::filter_eligible() answers a membership question and returns
	 * its own order; a hub's children and a place's articles both arrive in an
	 * order that means something, so this uses the answer as a filter rather
	 * than as a result.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function eligible( string $lang, array $ids ): array {
		if ( ! $ids ) {
			return [];
		}

		$keep = array_flip( array_column( MFY_Data::filter_eligible( $lang, $ids ), 'post_id' ) );

		return array_values( array_filter( $ids, static fn( $id ) => isset( $keep[ (int) $id ] ) ) );
	}

	/**
	 * Drops a row that says nothing a previous row did not.
	 *
	 * Repetition *between* rows is fine and expected — the same article can
	 * belong to several categories. A row whose every tile already appeared in
	 * one earlier row is a different thing: two headings over one set of
	 * articles, which reads as a bug however true both headings are. The most
	 * specific phrasing wins, because it is the one that came first.
	 */
	private static function drop_redundant( array $rows ): array {
		$seen = [];
		$out  = [];

		foreach ( $rows as $row ) {
			$ids       = array_flip( $row['ids'] );
			$redundant = false;

			foreach ( $seen as $previous ) {
				if ( ! array_diff_key( $ids, $previous ) ) {
					$redundant = true;
					break;
				}
			}

			if ( $redundant ) {
				continue;
			}

			$seen[] = $ids;
			$out[]  = $row;
		}

		return $out;
	}

	/** Post IDs become tiles. The expensive half, done once and last. */
	private static function hydrate( array $rows ): array {
		$out = [];

		foreach ( $rows as $row ) {
			$items = [];

			foreach ( $row['ids'] as $post_id ) {
				$item = MFY_Data::format_item( $post_id );

				if ( ! $item ) {
					continue;
				}

				$hub_type = MFY_Hubs::hub_type( $post_id );
				if ( $hub_type ) {
					$item['hub'] = [
						'type'  => $hub_type,
						'label' => MFY_Hubs::label( $hub_type, MFY_Data::post_lang( $post_id ) ),
					];
				}

				$items[] = $item;
			}

			// A row can lose tiles here only if a post vanished between the
			// query and now; below the minimum it is dropped, exactly as it
			// would have been during assembly.
			if ( 'recent' !== $row['kind'] && count( $items ) < MFY_Config::page_row_min() ) {
				continue;
			}
			if ( ! $items ) {
				continue;
			}

			unset( $row['ids'] );
			$row['items'] = $items;
			$out[]        = $row;
		}

		return $out;
	}

	private static function row_size(): int {
		return max( 1, MFY_Config::page_row_size() );
	}

	/** Administrator-only: which rows were built, from what, and how full. */
	private static function describe( array $rows ): array {
		$out = [];

		foreach ( $rows as $row ) {
			$out[] = [
				'kind'   => $row['kind'],
				'key'    => $row['key'],
				'title'  => $row['title'],
				'tiles'  => count( $row['ids'] ),
				'source' => $row['source'],
			];
		}

		return [ 'rows' => $out ];
	}
}

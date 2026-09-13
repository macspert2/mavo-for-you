<?php
/**
 * Tools → Mavo For You scoring — the instrument for tuning min_score.
 *
 * The threshold has never been set from data. It was a conservative guess made
 * before geography, hubs and the impersonal block existed, and all three have
 * changed what the numbers mean: a hub child clears it on one relationship
 * alone, and [geo_related] scores off a single synthetic view, so its totals
 * run at roughly a third of a three-article session's against the same bar.
 *
 * MFY_Scorer::rank() is a pure function of its inputs, so sessions can be
 * replayed against real content with no browsing at all. This page does that
 * for a sample of real posts, in four shapes, and reports what any candidate
 * threshold would cost in coverage.
 *
 * It writes nothing. It only reads, scores in memory, and prints.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Tuner {

	const PAGE_SLUG = 'mavo-for-you-scoring';
	const ACTION    = 'mfy_run_scoring_sample';

	/** Thresholds the sweep reports on. */
	const SWEEP = [ 0, 2.5, 5, 7.5, 10, 15, 20, 30, 40, 60 ];

	/** Session shapes, each a way of choosing the two companion views. */
	const SHAPES = [
		'impersonal' => 'Impersonal ([geo_related]) — one synthetic view',
		'focused'    => 'Focused — two more posts in the same place',
		'thematic'   => 'Thematic — two more sharing its strong filters',
		'scattered'  => 'Scattered — two random posts',
	];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
	}

	public static function add_page(): void {
		add_management_page(
			__( 'Mavo For You scoring', 'mavo-for-you' ),
			__( 'Mavo For You scoring', 'mavo-for-you' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Running the sample
	// -------------------------------------------------------------------------

	/**
	 * Scores a sample of real posts in every shape.
	 *
	 * @return array{sessions: array, skipped: int, seconds: float, budget_hit: bool}
	 */
	public static function run( string $lang, int $sample, int $budget_seconds = 45, string $order = 'views' ): array {
		$started    = microtime( true );
		$sessions   = [];
		$skips      = [];
		$health     = [ 'posts' => 0, 'strong_filters' => 0, 'any_filters' => 0, 'geo' => 0, 'hub' => 0, 'sampled_ids' => [], 'views' => [] ];
		$budget_hit = false;

		$sampled = self::sample_posts( $lang, $sample, [], $order );

		// One query for every view count, rather than one per post below.
		if ( $sampled ) {
			update_meta_cache( 'post', $sampled );
		}

		foreach ( $sampled as $post_id ) {
			$health['views'][] = (int) get_post_meta( $post_id, 'views', true );
			$h = self::post_health( $post_id, $lang );
			++$health['posts'];
			$health['strong_filters'] += $h['strong_filters'] > 0 ? 1 : 0;
			$health['any_filters']    += $h['any_filters'] > 0 ? 1 : 0;
			$health['geo']            += $h['has_geo'] ? 1 : 0;
			$health['hub']            += $h['has_hub'] ? 1 : 0;
			$health['sampled_ids'][]   = $post_id;

			foreach ( array_keys( self::SHAPES ) as $shape ) {
				if ( microtime( true ) - $started > $budget_seconds ) {
					$budget_hit = true;
					break 2;
				}

				$session = self::score_one( $post_id, $lang, $shape );

				if ( isset( $session['skipped'] ) ) {
					$skips[ $session['skipped'] ] = ( $skips[ $session['skipped'] ] ?? 0 ) + 1;
					continue;
				}

				$sessions[] = $session;
			}
		}

		arsort( $skips );

		// Independent cross-check. If the sample says 10% and the travel
		// finder's own tables say 90%, the sample is wrong — most likely the
		// language filter — and nothing else on this page can be trusted.
		$health['site_weighted']  = class_exists( 'TVF_Store' )
			? ( TVF_Store::count_weighted_posts()[ $lang ] ?? 0 )
			: null;
		$health['site_published'] = self::published_count( $lang );
		$health['sample_langs']   = self::sampled_languages( $health['sampled_ids'] );
		$health['order']          = $order;
		$health['views_median']   = self::percentile( $health['views'], 50 );

		return [
			'sessions'   => $sessions,
			'skips'      => $skips,
			'health'     => $health,
			'seconds'    => round( microtime( true ) - $started, 1 ),
			'budget_hit' => $budget_hit,
		];
	}

	/** One session: build the views, rank, keep the scores. */
	private static function score_one( int $post_id, string $lang, string $shape ): array {
		$built = self::build_views( $post_id, $lang, $shape );
		$views = $built['views'];

		if ( ! $views ) {
			return [ 'skipped' => $shape . ': ' . $built['reason'] ];
		}

		$ranked = MFY_Scorer::rank( $post_id, $lang, $views, [], null );
		$debug  = $ranked['debug'];

		// Every scored candidate, threshold or no threshold: rank() records
		// these before it applies min_score, which is exactly what makes a
		// sweep possible without re-running anything.
		$scores = array_map(
			static fn( $c ) => (float) $c['score'],
			$debug['candidates'] ?? []
		);
		rsort( $scores );

		$hub_slots = (int) ( $debug['composition']['slots_hub'] ?? 0 );

		return [
			'shape'      => $shape,
			'post_id'    => $post_id,
			'title'      => get_the_title( $post_id ),
			'views'      => count( $views ),
			'candidates' => count( $scores ),
			'scores'     => array_slice( $scores, 0, 6 ),
			'hub_slots'  => $hub_slots,
			'shown'      => count( $ranked['recommendations'] ),
		];
	}

	/**
	 * The views for one shape.
	 *
	 * Engagement is held at 30s / 50% throughout — both sit in the 1.00 band,
	 * so every view weighs exactly its recency and the shapes stay comparable.
	 * Varying engagement is a second experiment, not this one.
	 */
	private static function build_views( int $post_id, string $lang, string $shape ): array {
		$now  = time();
		$view = static fn( int $id, int $age ) => [
			'post_id'          => $id,
			'duration_seconds' => 30,
			'max_scroll_pct'   => 50,
			'last_seen'        => $now - $age,
		];

		if ( 'impersonal' === $shape ) {
			return [ 'views' => [ $view( $post_id, 0 ) ], 'reason' => '' ];
		}

		$companions = self::companions( $post_id, $lang, $shape );

		if ( count( $companions['ids'] ) < 2 ) {
			return [ 'views' => [], 'reason' => $companions['reason'] ?: 'not enough material' ];
		}

		return [
			'views'  => [
				$view( $post_id, 0 ),
				$view( $companions['ids'][0], 400 ),
				$view( $companions['ids'][1], 800 ),
			],
			'reason' => '',
		];
	}

	/**
	 * Two other posts, chosen the way the shape describes.
	 *
	 * Returns the reason as well as the IDs, because "skipped" on its own tells
	 * you nothing: a shape that cannot be built because the post has no filter
	 * scores is a different finding from one that cannot be built because
	 * nothing else shares them.
	 *
	 * @return array{ids: int[], reason: string}
	 */
	private static function companions( int $post_id, string $lang, string $shape ): array {
		if ( 'focused' === $shape ) {
			$places = MFY_Geo::places_for_posts( [ $post_id ], $lang )[ $post_id ] ?? [];
			$place  = $places['city'] ?? $places['region'] ?? $places['country'] ?? 0;

			if ( ! $place ) {
				return [ 'ids' => [], 'reason' => 'no geography on the post' ];
			}

			$ids = array_slice( MFY_Geo::candidate_ids( $lang, [ $place ], [ $post_id ], 8 ), 0, 2 );

			return [ 'ids' => $ids, 'reason' => count( $ids ) < 2 ? 'too few posts in the same place' : '' ];
		}

		if ( 'thematic' === $shape ) {
			$strong = array_keys( array_filter(
				MFY_Data::get_filter_scores( $post_id, $lang ),
				static fn( $score ) => 2 === (int) $score
			) );

			if ( ! $strong ) {
				return [ 'ids' => [], 'reason' => 'no filter scored 2 on the post' ];
			}

			$ids = array_slice( array_column(
				MFY_Data::get_candidates( $lang, $strong, [ $post_id ], 8 ), 'post_id' ), 0, 2 );

			return [ 'ids' => $ids, 'reason' => count( $ids ) < 2 ? 'too few posts share its filters' : '' ];
		}

		$ids = array_slice( self::sample_posts( $lang, 3, [ $post_id ] ), 0, 2 );

		return [ 'ids' => $ids, 'reason' => count( $ids ) < 2 ? 'too few posts in this language' : '' ];
	}

	/**
	 * What a post brings to the engine before any session is built.
	 *
	 * The three things a recommendation can be made from. A post with none of
	 * them cannot be helped by any threshold.
	 */
	public static function post_health( int $post_id, string $lang ): array {
		$scores = MFY_Data::get_filter_scores( $post_id, $lang );

		return [
			'strong_filters' => count( array_filter( $scores, static fn( $s ) => 2 === (int) $s ) ),
			'any_filters'    => count( array_filter( $scores, static fn( $s ) => (int) $s > 0 ) ),
			'has_geo'        => (bool) ( MFY_Geo::places_for_posts( [ $post_id ], $lang )[ $post_id ] ?? [] ),
			'has_hub'        => MFY_Hubs::available()
				&& (bool) MFY_Hubs::session_context( [ [ 'post_id' => $post_id, 'weight' => 1.0 ] ], $lang ),
		];
	}

	/**
	 * Published posts of a language, at random.
	 *
	 * orderby rand is fine here: this runs on demand, for an administrator,
	 * over a bounded sample — never on a page a visitor sees.
	 *
	 * @return int[]
	 */
	private static function sample_posts( string $lang, int $n, array $exclude = [], string $order = 'random' ): array {
		$args = [
			'post_type'              => MFY_Config::candidate_post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => $n,
			'orderby'                => 'rand',
			'fields'                 => 'ids',
			'post__not_in'           => $exclude,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( 'views' === $order ) {
			// The site's own view counter, the same meta the travel finder
			// ranks on. Note the join is inner: a post with no views row is
			// absent rather than last, which for "most read" is what you want.
			$args['meta_key'] = 'views';
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'DESC';
		}

		if ( function_exists( 'pll_get_post_language' ) ) {
			$args['lang'] = $lang; // Polylang reads this.
		}

		return array_map( 'absint', get_posts( $args ) );
	}

	// -------------------------------------------------------------------------
	// Analysis — pure, and therefore testable
	// -------------------------------------------------------------------------

	/**
	 * What each candidate threshold would cost.
	 *
	 * The question a threshold actually poses is not "is 7.5 right" but "how
	 * many sessions still fill the block at 7.5, and how many show nothing".
	 * Hub picks are excluded from the count because they bypass the threshold
	 * entirely — a session can show a hub and nothing else.
	 *
	 * @param array $sessions From run().
	 */
	public static function sweep( array $sessions ): array {
		$rows = [];

		foreach ( self::SWEEP as $t ) {
			$full = $some = $none = 0;

			foreach ( $sessions as $s ) {
				$above = count( array_filter( $s['scores'], static fn( $v ) => $v >= $t ) );

				if ( $above >= 3 )      { ++$full; }
				elseif ( $above >= 1 )  { ++$some; }
				else                    { ++$none; }
			}

			$n = max( 1, count( $sessions ) );

			$rows[] = [
				'threshold' => $t,
				'full'      => round( 100 * $full / $n ),
				'some'      => round( 100 * $some / $n ),
				'none'      => round( 100 * $none / $n ),
			];
		}

		return $rows;
	}

	/** Percentiles of the top score and the third score, per shape. */
	public static function distribution( array $sessions ): array {
		$out = [];

		foreach ( array_keys( self::SHAPES ) as $shape ) {
			$subset = array_values( array_filter( $sessions, static fn( $s ) => $s['shape'] === $shape ) );

			if ( ! $subset ) {
				continue;
			}

			$top   = array_map( static fn( $s ) => $s['scores'][0] ?? 0.0, $subset );
			$third = array_map( static fn( $s ) => $s['scores'][2] ?? 0.0, $subset );

			$out[ $shape ] = [
				'n'         => count( $subset ),
				'top_p10'   => self::percentile( $top, 10 ),
				'top_med'   => self::percentile( $top, 50 ),
				'top_p90'   => self::percentile( $top, 90 ),
				'third_p10' => self::percentile( $third, 10 ),
				'third_med' => self::percentile( $third, 50 ),
				'third_p90' => self::percentile( $third, 90 ),
				'ratio_med' => self::percentile( array_map(
					static fn( $s ) => ( $s['scores'][0] ?? 0 ) > 0
						? round( ( $s['scores'][2] ?? 0 ) / $s['scores'][0], 3 )
						: 0.0,
					$subset
				), 50 ),
			];
		}

		return $out;
	}

	/** Published posts in a language, for the coverage cross-check. */
	private static function published_count( string $lang ): int {
		if ( function_exists( 'pll_count_posts' ) ) {
			return (int) pll_count_posts( $lang );
		}

		$counts = wp_count_posts( 'post' );

		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * The languages the sample actually drew from.
	 *
	 * If this shows more than the language asked for, get_posts ignored the
	 * Polylang argument and every filter-score lookup was made against the
	 * wrong language — which would explain a low coverage figure without any
	 * content being missing at all.
	 *
	 * @param int[] $ids
	 */
	private static function sampled_languages( array $ids ): array {
		$out = [];

		foreach ( $ids as $id ) {
			$l = MFY_Data::post_lang( $id ) ?: '?';
			$out[ $l ] = ( $out[ $l ] ?? 0 ) + 1;
		}

		arsort( $out );

		return $out;
	}

	/** Nearest-rank percentile. Small samples do not deserve interpolation. */
	public static function percentile( array $values, int $p ): float {
		$values = array_values( array_filter( $values, static fn( $v ) => null !== $v ) );

		if ( ! $values ) {
			return 0.0;
		}

		sort( $values );
		$index = (int) ceil( $p / 100 * count( $values ) ) - 1;

		return round( (float) $values[ max( 0, min( count( $values ) - 1, $index ) ) ], 2 );
	}

	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$langs  = MFY_Config::site_langs();
		$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : ( $langs[0] ?? 'fr' );
		$sample = isset( $_POST['sample'] ) ? max( 1, min( 200, absint( $_POST['sample'] ) ) ) : 40;
		$order  = isset( $_POST['order'] ) && 'random' === $_POST['order'] ? 'random' : 'views';
		$result = null;

		if ( isset( $_POST['mfy_run'] ) && check_admin_referer( self::ACTION ) ) {
			$result = self::run( in_array( $lang, $langs, true ) ? $lang : 'fr', $sample, 45, $order );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mavo For You — scoring', 'mavo-for-you' ); ?></h1>

			<p style="max-width:46em">
				<?php
				esc_html_e(
					'Replays synthetic sessions against real posts and reports what each candidate threshold would cost. Reads only — nothing is written, and no visitor is affected.',
					'mavo-for-you'
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mfy-lang"><?php esc_html_e( 'Language', 'mavo-for-you' ); ?></label></th>
						<td>
							<select name="lang" id="mfy-lang">
								<?php foreach ( $langs as $l ) : ?>
									<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $l, $lang ); ?>><?php echo esc_html( strtoupper( $l ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mfy-order"><?php esc_html_e( 'Which posts', 'mavo-for-you' ); ?></label></th>
						<td>
							<select name="order" id="mfy-order">
								<option value="views" <?php selected( 'views', $order ); ?>><?php esc_html_e( 'Most viewed — where readers actually land', 'mavo-for-you' ); ?></option>
								<option value="random" <?php selected( 'random', $order ); ?>><?php esc_html_e( 'Random — the catalogue as a whole', 'mavo-for-you' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'These answer different questions. Most viewed measures the block where it is actually seen, and those posts are the ones most likely to be scored, tagged and hubbed. Random measures the catalogue, tail included. Run both: the gap between them is the finding.', 'mavo-for-you' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mfy-sample"><?php esc_html_e( 'Posts to sample', 'mavo-for-you' ); ?></label></th>
						<td>
							<input type="number" name="sample" id="mfy-sample" min="1" max="200" value="<?php echo esc_attr( (string) $sample ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Each post is scored in four shapes. 40 posts ≈ 160 sessions and takes a few seconds; the run stops after 45 seconds whatever happens.', 'mavo-for-you' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Run sample', 'mavo-for-you' ), 'primary', 'mfy_run' ); ?>
			</form>

			<?php if ( $result ) : self::render_results( $result ); endif; ?>
		</div>
		<?php
	}

	private static function render_results( array $result ): void {
		$sessions = $result['sessions'];
		$current  = MFY_Config::min_score();
		$h        = $result['health'];
		$n        = max( 1, $h['posts'] );
		?>
		<hr>
		<h2><?php esc_html_e( 'Sample health', 'mavo-for-you' ); ?></h2>
		<p style="max-width:52em">
			<?php esc_html_e( 'What the sampled posts bring to the engine before any threshold applies. A post with none of these cannot be helped by tuning.', 'mavo-for-you' ); ?>
		</p>
		<table class="widefat striped" style="max-width:52em">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Population measured', 'mavo-for-you' ); ?></td>
					<td colspan="2">
						<?php
						echo esc_html( 'views' === ( $h['order'] ?? 'views' )
							? sprintf( __( 'The %d most viewed posts (median %s views)', 'mavo-for-you' ), $h['posts'], (string) $h['views_median'] )
							: sprintf( __( '%d posts at random (median %s views)', 'mavo-for-you' ), $h['posts'], (string) $h['views_median'] ) );
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Posts with at least one filter scored 2', 'mavo-for-you' ); ?></td>
					<td><strong><?php echo esc_html( sprintf( '%d / %d (%d%%)', $h['strong_filters'], $h['posts'], round( 100 * $h['strong_filters'] / $n ) ) ); ?></strong></td>
					<td class="description"><?php esc_html_e( 'Eligibility for the filter pool, and the only thing §10 accepts.', 'mavo-for-you' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Posts with any filter scored above 0', 'mavo-for-you' ); ?></td>
					<td><?php echo esc_html( sprintf( '%d / %d (%d%%)', $h['any_filters'], $h['posts'], round( 100 * $h['any_filters'] / $n ) ) ); ?></td>
					<td class="description"><?php esc_html_e( 'Scored at all, even weakly.', 'mavo-for-you' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Posts with geography', 'mavo-for-you' ); ?></td>
					<td><?php echo esc_html( sprintf( '%d / %d (%d%%)', $h['geo'], $h['posts'], round( 100 * $h['geo'] / $n ) ) ); ?></td>
					<td class="description"><?php esc_html_e( 'A city, region or country from Geo Tagger.', 'mavo-for-you' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Posts inside a hub', 'mavo-for-you' ); ?></td>
					<td><?php echo esc_html( sprintf( '%d / %d (%d%%)', $h['hub'], $h['posts'], round( 100 * $h['hub'] / $n ) ) ); ?></td>
					<td class="description"><?php esc_html_e( 'Reachable by the strongest signal there is.', 'mavo-for-you' ); ?></td>
				</tr>
			</tbody>
		</table>

		<?php
		$site_pct = $h['site_published'] ? round( 100 * (int) $h['site_weighted'] / $h['site_published'] ) : null;
		$sample_pct = round( 100 * $h['strong_filters'] / $n );
		?>
		<h3><?php esc_html_e( 'Cross-check — is the sample telling the truth?', 'mavo-for-you' ); ?></h3>
		<table class="widefat striped" style="max-width:52em">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Travel Finder rows, site-wide, this language', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						echo null === $h['site_weighted']
							? esc_html__( 'Travel Finder not active', 'mavo-for-you' )
							: esc_html( sprintf( '%d posts carry filter rows, of %d published (%s%%)',
								(int) $h['site_weighted'], (int) $h['site_published'],
								null === $site_pct ? '?' : $site_pct ) );
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Languages the sample drew from', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						$parts = [];
						foreach ( $h['sample_langs'] as $l => $count ) { $parts[] = "$l: $count"; }
						echo esc_html( implode( ', ', $parts ) );
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Verdict', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						if ( count( $h['sample_langs'] ) > 1 ) {
							echo '<strong>' . esc_html__( 'The sample crossed languages — filter scores were looked up against the wrong one. Ignore the figures above.', 'mavo-for-you' ) . '</strong>';
						} elseif ( 'views' === ( $h['order'] ?? 'views' ) ) {
							/* A most-viewed sample is *meant* to beat the site-wide
							   figure; the gap is the finding, not a fault. */
							echo esc_html( sprintf(
								/* translators: 1: sample %, 2: site-wide % */
								__( 'Most-viewed sample: %1$d%% scored, against %2$s%% site-wide. A gap here means the tail is unscored while the pages readers reach are fine; no gap means the shortage is everywhere.', 'mavo-for-you' ),
								$sample_pct, null === $site_pct ? '?' : (string) $site_pct ) );
						} elseif ( null !== $site_pct && abs( $site_pct - $sample_pct ) > 25 ) {
							echo '<strong>' . esc_html( sprintf(
								/* translators: 1: site %, 2: sample % */
								__( 'Random sample (%2$d%%) and site-wide (%1$d%%) disagree sharply — treat the sample as unrepresentative.', 'mavo-for-you' ),
								$site_pct, $sample_pct ) ) . '</strong>';
						} else {
							esc_html_e( 'Random sample and site-wide figures agree; the coverage reading is real.', 'mavo-for-you' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $result['skips'] ) ) : ?>
			<h3><?php esc_html_e( 'Why shapes were skipped', 'mavo-for-you' ); ?></h3>
			<table class="widefat striped" style="max-width:52em">
				<tbody>
				<?php foreach ( $result['skips'] as $reason => $count ) : ?>
					<tr><td><?php echo esc_html( $reason ); ?></td><td><?php echo esc_html( (string) $count ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'What each threshold would cost', 'mavo-for-you' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: session count, 2: seconds, 3: current threshold */
				esc_html__( '%1$d sessions in %2$ss. Current min_score is %3$s — the highlighted row.', 'mavo-for-you' ),
				count( $sessions ),
				esc_html( (string) $result['seconds'] ),
				esc_html( (string) $current )
			);
			if ( $result['budget_hit'] ) {
				echo ' <strong>' . esc_html__( 'Stopped at the time budget; sample is partial.', 'mavo-for-you' ) . '</strong>';
			}
			if ( $result['skips'] ) {
				printf( ' ' . esc_html__( '%d shapes skipped — see above for why.', 'mavo-for-you' ), (int) array_sum( $result['skips'] ) );
			}
			?>
		</p>

		<table class="widefat striped" style="max-width:52em">
			<thead><tr>
				<th><?php esc_html_e( 'min_score', 'mavo-for-you' ); ?></th>
				<th><?php esc_html_e( 'full block (3+)', 'mavo-for-you' ); ?></th>
				<th><?php esc_html_e( 'thin (1–2)', 'mavo-for-you' ); ?></th>
				<th><?php esc_html_e( 'nothing', 'mavo-for-you' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( self::sweep( $sessions ) as $row ) : ?>
				<tr<?php echo abs( $row['threshold'] - $current ) < 0.01 ? ' style="font-weight:700;background:#fff8e5"' : ''; ?>>
					<td><?php echo esc_html( (string) $row['threshold'] ); ?></td>
					<td><?php echo esc_html( $row['full'] ); ?>%</td>
					<td><?php echo esc_html( $row['some'] ); ?>%</td>
					<td><?php echo esc_html( $row['none'] ); ?>%</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description" style="max-width:52em">
			<?php esc_html_e( 'Hub picks are not counted here: they bypass the threshold entirely, so a session in the "nothing" column may still show its hub.', 'mavo-for-you' ); ?>
		</p>

		<h2><?php esc_html_e( 'Where the scores actually sit', 'mavo-for-you' ); ?></h2>
		<table class="widefat striped" style="max-width:64em">
			<thead><tr>
				<th><?php esc_html_e( 'Shape', 'mavo-for-you' ); ?></th>
				<th>n</th>
				<th><?php esc_html_e( 'top p10 / median / p90', 'mavo-for-you' ); ?></th>
				<th><?php esc_html_e( '3rd p10 / median / p90', 'mavo-for-you' ); ?></th>
				<th><?php esc_html_e( '3rd ÷ top (median)', 'mavo-for-you' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( self::distribution( $sessions ) as $shape => $d ) : ?>
				<tr>
					<td><?php echo esc_html( self::SHAPES[ $shape ] ?? $shape ); ?></td>
					<td><?php echo esc_html( (string) $d['n'] ); ?></td>
					<td><?php echo esc_html( "{$d['top_p10']} / {$d['top_med']} / {$d['top_p90']}" ); ?></td>
					<td><?php echo esc_html( "{$d['third_p10']} / {$d['third_med']} / {$d['third_p90']}" ); ?></td>
					<td><?php echo esc_html( (string) $d['ratio_med'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description" style="max-width:64em">
			<?php
			esc_html_e(
				'If the impersonal row sits far below the others, one absolute threshold is doing two jobs and the last column is the alternative: a relative gate — drop anything below a share of the top score — is scale-free and survives future scoring changes.',
				'mavo-for-you'
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Raw rows', 'mavo-for-you' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Copy into a spreadsheet to judge candidates by hand, which is the part no distribution can answer.', 'mavo-for-you' ); ?></p>
		<textarea rows="12" style="width:100%;font-family:monospace;font-size:12px" readonly onclick="this.select()"><?php
			echo esc_textarea( self::to_csv( $sessions ) );
		?></textarea>
		<?php
	}

	public static function to_csv( array $sessions ): string {
		$out = "shape,post_id,title,views,candidates,shown,hub_slots,s1,s2,s3,s4,s5\n";

		foreach ( $sessions as $s ) {
			$scores = array_pad( array_slice( $s['scores'], 0, 5 ), 5, '' );
			$out   .= sprintf(
				"%s,%d,\"%s\",%d,%d,%d,%d,%s\n",
				$s['shape'],
				$s['post_id'],
				str_replace( '"', "'", $s['title'] ),
				$s['views'],
				$s['candidates'],
				$s['shown'],
				$s['hub_slots'],
				implode( ',', $scores )
			);
		}

		return $out;
	}
}

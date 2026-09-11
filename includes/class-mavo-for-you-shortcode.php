<?php
/**
 * [geo_related] — the impersonal block, rendered into the cached page.
 *
 * It answers "what else should someone reading *this page* read?", with no
 * visitor in the question: the page's hub first, then its geographic and
 * editorial siblings. Being impersonal is what lets it live in cached HTML.
 *
 * It is the same block as "Pour vous", in the same markup, scored by the same
 * code — MFY_Scorer::rank_for_post() is simply rank() with a session of one
 * view. Once the visitor has read enough for real personalization, the
 * frontend controller replaces this block's contents in place. The swap is
 * invisible because there is only ever one set of markup and one stylesheet.
 *
 * Moved here from mavo-geotag-plus, which had grown a dependency on this
 * plugin's hub labels to keep the two blocks' wording in step. The scoring
 * lives here; the place tree stays there.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Shortcode {

	const TAG      = 'geo_related';
	const TAG_FULL = 'geo_related_full';

	/** Post IDs whose content has already produced a block this request. */
	private static array $rendered = [];

	public static function init(): void {
		add_shortcode( self::TAG, [ __CLASS__, 'render_shortcode' ] );

		// Existing post content still contains [geo_related_full], which used
		// to stack one section per geographic level. The simplified block has
		// no levels to stack, so it is an alias — an unregistered shortcode
		// would render as literal text on every post that uses it.
		add_shortcode( self::TAG_FULL, [ __CLASS__, 'render_shortcode' ] );
	}

	/**
	 * Does this post's stored content place the block itself?
	 *
	 * Decided from post_content rather than from whether the shortcode has run,
	 * because the assets are enqueued before the_content is rendered.
	 */
	public static function post_has_shortcode( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, self::TAG )
			|| has_shortcode( $post->post_content, self::TAG_FULL );
	}

	/**
	 * @param array|string $atts 'level' (city|region|country) and 'limit'.
	 *                           Every other attribute the old shortcode took —
	 *                           post_id, style — is deliberately ignored.
	 */
	public static function render_shortcode( $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'level' => '',
				'limit' => MFY_Config::shortcode_limit(),
			],
			(array) $atts,
			self::TAG
		);

		$post_id = (int) get_the_ID();

		if ( ! $post_id || isset( self::$rendered[ $post_id ] ) ) {
			return ''; // Two shortcodes in one post get one block.
		}

		$level = in_array( $atts['level'], MFY_Geo::levels(), true ) ? (string) $atts['level'] : '';
		$limit = max( 1, min( MFY_Config::shortcode_max_limit(), (int) $atts['limit'] ) );

		$html = self::render_block( $post_id, $level, $limit );

		if ( '' === $html ) {
			return '';
		}

		self::$rendered[ $post_id ] = true;

		return $html;
	}

	/** Has a block already been emitted for this post this request? */
	public static function has_rendered( int $post_id ): bool {
		return isset( self::$rendered[ $post_id ] );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * The placeholder, with the impersonal block already inside it.
	 *
	 * Same container and same id the after-content placeholder uses, so the
	 * frontend controller neither knows nor cares which of the two put it
	 * there — it finds #mavo-for-you and replaces its contents.
	 */
	public static function render_block( int $post_id, string $level, int $limit ): string {
		$lang = MFY_Data::post_lang( $post_id );

		if ( '' === $lang || ! MFY_Data::should_track_post( $post_id ) ) {
			return '';
		}

		$recommendations = self::recommendations( $post_id, $lang, $level, $limit );
		$labels          = MFY_Config::labels( $lang );

		// The placeholder is emitted even with nothing to put in it. An empty
		// one is invisible (.mavo-for-you-placeholder:empty), costs nothing,
		// and is what a visitor who later earns a personalized block gets it
		// in — this hook having already stood down for the after-content one.
		ob_start();
		?>
		<div id="mavo-for-you" class="mavo-for-you-placeholder"
			data-current-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
			data-limit="<?php echo esc_attr( (string) $limit ); ?>"
			data-level="<?php echo esc_attr( $level ); ?>">
			<?php if ( $recommendations ) : ?>
			<section class="mfy mfy--impersonal is-visible" aria-labelledby="mfy-title">
				<header class="mfy__header">
					<h2 class="mfy__title" id="mfy-title"><?php echo esc_html( $labels['impersonalHeading'] ); ?></h2>
				</header>
				<div class="mfy__grid">
					<?php foreach ( $recommendations as $item ) { echo self::card( $item ); } ?>
				</div>
			</section>
			<?php endif; ?>
		</div>
		<?php

		return (string) apply_filters(
			'mavo_for_you_shortcode_html',
			trim( (string) ob_get_clean() ),
			$post_id,
			$recommendations
		);
	}

	/**
	 * One card, in exactly the markup assets/js/mavo-for-you.js builds.
	 *
	 * The two renderers have to agree character for character in class names,
	 * or the personalized block would restyle itself on arrival.
	 */
	private static function card( array $item ): string {
		$classes = 'mv-tile mv-tile--media mfy__card';
		$classes .= $item['image'] ? '' : ' mv-tile--no-media';
		$classes .= empty( $item['hub'] ) ? '' : ' mfy__card--hub';

		$html = '<div class="' . esc_attr( $classes ) . '">';

		if ( $item['image'] ) {
			$html .= '<span class="mv-tile__media">'
				. '<img class="mv-tile__img" src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy" decoding="async">'
				. '</span>';
		}

		$html .= '<span class="mv-tile__body">';

		if ( ! empty( $item['hub']['label'] ) ) {
			$html .= '<span class="mv-tile__eyebrow mfy__hub-label">' . esc_html( $item['hub']['label'] ) . '</span>';
		}

		$html .= '<span class="mv-tile__title">'
			. '<a class="mv-tile__link" href="' . esc_url( $item['url'] ) . '"'
			. mavo_for_you_link_data_attr( $item['post_id'] ) . '>'
			. esc_html( $item['title'] )
			. '</a></span>';

		if ( ! empty( $item['excerpt'] ) ) {
			$html .= '<span class="mv-tile__description">' . esc_html( self::trim_excerpt( $item['excerpt'] ) ) . '</span>';
		}

		return $html . '</span></div>';
	}

	/** Mirrors the frontend's trim(): same length, same word boundary, same ellipsis. */
	private static function trim_excerpt( string $text, int $length = 130 ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $length );
		$space = mb_strrpos( $cut, ' ' );

		return ( $space > 40 ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/**
	 * The picks, cached.
	 *
	 * The block normally renders into a page that Swift and Cloudflare then
	 * cache, so this runs rarely — but "rarely" is not "never" (logged-in
	 * views, purges, cache misses), and a miss costs the full ranking. The
	 * transient carries a generation counter that any post save or hub change
	 * bumps, so stale picks cannot outlive an edit. See MFY_Cache.
	 */
	private static function recommendations( int $post_id, string $lang, string $level, int $limit ): array {
		$key    = MFY_Cache::key( 'sc', [ $post_id, $lang, $level, $limit ] );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$options = [ 'limit' => $limit ];

		if ( '' !== $level ) {
			$options['geo_levels'] = [ $level ];
		}

		$ranked = MFY_Scorer::rank_for_post( $post_id, $lang, $options );

		set_transient( $key, $ranked['recommendations'], MFY_Config::shortcode_cache_ttl() );

		return $ranked['recommendations'];
	}
}

/**
 * Back-compat shims for the helpers mavo-geotag-plus exposed.
 *
 * Nothing in the theme calls them today, but they were documented as a public
 * API, so they answer rather than fatal. The $level and $limit arguments still
 * mean what they meant; $style no longer does anything.
 */
if ( ! function_exists( 'geo_tagger_related_posts' ) ) :

function geo_tagger_related_posts( int $post_id = 0, ?string $level = null, string $style = 'plain', int $limit = 6 ): string {
	$post_id = $post_id ?: (int) get_the_ID();
	$level   = in_array( (string) $level, MFY_Geo::levels(), true ) ? (string) $level : '';

	return $post_id ? MFY_Shortcode::render_block( $post_id, $level, $limit ) : '';
}

function geo_tagger_related_posts_full( int $post_id = 0, string $style = 'plain', int $limit = 6 ): string {
	return geo_tagger_related_posts( $post_id, null, $style, $limit );
}

endif;

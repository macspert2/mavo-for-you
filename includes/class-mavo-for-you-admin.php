<?php
/**
 * Settings → Mavo For You.
 *
 * One setting: which languages the block is switched on for. Everything else
 * is a constant or a filter (see MFY_Config), deliberately — a V0 does not
 * need a scoring UI.
 */

defined( 'ABSPATH' ) || exit;

class MFY_Admin {

	const PAGE_SLUG = 'mavo-for-you';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	public static function add_page(): void {
		add_options_page(
			__( 'Mavo For You', 'mavo-for-you' ),
			__( 'Mavo For You', 'mavo-for-you' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			'mavo_for_you',
			MFY_Config::OPTION_ENABLED_LANGS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize_langs' ],
				'default'           => MFY_Config::site_langs(),
			]
		);
	}

	/** Only slugs the site actually has; anything else is dropped. */
	public static function sanitize_langs( $value ): array {
		$known = MFY_Config::site_langs();
		$value = array_map( 'sanitize_key', (array) $value );

		return array_values( array_intersect( $known, $value ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled = MFY_Config::enabled_langs();
		$langs   = MFY_Config::site_langs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mavo For You', 'mavo-for-you' ); ?></h1>

			<p>
				<?php
				esc_html_e(
					'Adds a personalized "For you" block after the content of posts and pages. Recommendations are computed from the visitor\'s browsing during the current session only, and are loaded after page load so full-page caching is unaffected.',
					'mavo-for-you'
				);
				?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'mavo_for_you' ); ?>

				<h2><?php esc_html_e( 'Active languages', 'mavo-for-you' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The block is rendered, and browsing is tracked, only in the languages selected here.', 'mavo-for-you' ); ?>
				</p>

				<?php // Ensures an all-unchecked submission still posts the field, so the option can be emptied. ?>
				<input type="hidden" name="<?php echo esc_attr( MFY_Config::OPTION_ENABLED_LANGS ); ?>[]" value="">

				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $langs as $lang ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( strtoupper( $lang ) ); ?></th>
							<td>
								<label>
									<input type="checkbox"
									       name="<?php echo esc_attr( MFY_Config::OPTION_ENABLED_LANGS ); ?>[]"
									       value="<?php echo esc_attr( $lang ); ?>"
										<?php checked( in_array( $lang, $enabled, true ) ); ?>>
									<?php
									printf(
										/* translators: %s: language heading, e.g. "Pour vous". */
										esc_html__( 'Show "%s" on this language', 'mavo-for-you' ),
										esc_html( MFY_Config::labels( $lang )['heading'] )
									);
									?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Status', 'mavo-for-you' ); ?></h2>
			<table class="widefat striped" style="max-width:56em">
				<tbody>
				<tr>
					<td><?php esc_html_e( 'Travel Finder filter data', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						echo MFY_Data::integration_available()
							? esc_html__( 'available', 'mavo-for-you' )
							: '<strong>' . esc_html__( 'unavailable — no recommendations can be produced', 'mavo-for-you' ) . '</strong>';
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Polylang', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						echo function_exists( 'pll_get_post_language' )
							? esc_html__( 'available', 'mavo-for-you' )
							: '<strong>' . esc_html__( 'unavailable — the block stays hidden rather than risk mixing languages', 'mavo-for-you' ) . '</strong>';
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Geo Tagger place data', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						echo MFY_Geo::available()
							? esc_html__( 'available — suggestions favour the place the visitor is reading about', 'mavo-for-you' )
							: esc_html__( 'unavailable — suggestions fall back to filter scores alone', 'mavo-for-you' );
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Hub Manager relationships', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						echo MFY_Hubs::available()
							? esc_html__( 'available — an owning hub is offered as the first suggestion', 'mavo-for-you' )
							: esc_html__( 'unavailable — suggestions are made without hub awareness', 'mavo-for-you' );
						?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Signal filters', 'mavo-for-you' ); ?></td>
					<td><?php echo esc_html( implode( ', ', MFY_Data::signal_slugs() ) ?: '—' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Debug mode', 'mavo-for-you' ); ?></td>
					<td>
						<?php
						printf(
							/* translators: %s: query string to append to a URL. */
							esc_html__( 'Append %s to any post URL while logged in as an administrator. Scoring details are printed to the browser console and shown under the block.', 'mavo-for-you' ),
							'<code>?' . esc_html( MFY_Config::DEBUG_QUERY_ARG ) . '=1</code>'
						);
						?>
					</td>
				</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}

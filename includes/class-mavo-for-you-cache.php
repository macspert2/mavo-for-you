<?php
/**
 * Transient keys that expire themselves when the content behind them changes.
 *
 * The pattern is the travel-finder's, and for the same reason: invalidation by
 * deleting rows means a LIKE sweep of wp_options on every post save, which on
 * this site profiled in seconds, not milliseconds. Bumping one integer instead
 * makes every key built from the old generation unreachable at once, and the
 * orphans lapse on their own TTL.
 *
 * Only the impersonal shortcode block is cached. Personalized responses are
 * never cached anywhere — see MFY_Rest::respond().
 */

defined( 'ABSPATH' ) || exit;

class MFY_Cache {

	const PREFIX           = 'mfy_';
	const GENERATION_OPTION = 'mavo_for_you_cache_generation';

	public static function init(): void {
		// Any published content or relationship can change what the block
		// should show, so all of them retire the cache.
		add_action( 'save_post', [ __CLASS__, 'bust' ] );
		add_action( 'deleted_post', [ __CLASS__, 'bust' ] );
		add_action( 'mavo_hub_relationship_changed', [ __CLASS__, 'bust' ] );
		add_action( 'mavo_hub_type_changed', [ __CLASS__, 'bust' ] );
	}

	/** A key carrying the current generation, so a bump orphans it. */
	public static function key( string $namespace, array $parts ): string {
		return self::PREFIX . $namespace . '_' . self::generation() . '_' . md5( implode( ':', $parts ) );
	}

	public static function generation(): int {
		return max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) );
	}

	/**
	 * Retires every cached block at once. O(1): one option row, whatever the
	 * cache holds, so this is safe on save_post. Two concurrent bumps racing to
	 * the same value still invalidate correctly — the number only has to change.
	 */
	public static function bust(): void {
		// autoload = false: read on demand, never needed on every page load.
		update_option( self::GENERATION_OPTION, self::generation() + 1, false );
	}
}

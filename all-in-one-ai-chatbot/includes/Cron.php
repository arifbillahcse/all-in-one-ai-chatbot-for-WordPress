<?php
/**
 * Background work.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant;

use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Daily maintenance and the background index build.
 *
 * WP-Cron only runs when the site gets traffic, which is fine for both jobs:
 * neither is time-critical, and the admin screen can build the index
 * interactively when the owner does not want to wait.
 */
final class Cron {

	public const DAILY = 'softorio_ai_daily';
	public const BUILD = 'softorio_ai_build_index';

	/**
	 * Hook the handlers.
	 */
	public static function init(): void {
		add_action( self::DAILY, array( self::class, 'daily' ) );
		add_action( self::BUILD, array( self::class, 'build' ) );
	}

	/**
	 * Schedule recurring work and kick off the first index build.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}

		self::queue_build( 0 );
	}

	/**
	 * Remove every scheduled event of the plugin.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::BUILD );
		wp_clear_scheduled_hook( 'softorio_ai_index_post' );
		wp_clear_scheduled_hook( Support\Queue::HOOK );
	}

	/**
	 * Queue the next batch of an index build.
	 *
	 * @param int $offset Where to continue.
	 */
	public static function queue_build( int $offset ): void {
		if ( ! wp_next_scheduled( self::BUILD, array( $offset ) ) ) {
			wp_schedule_single_event( time() + 5, self::BUILD, array( $offset ) );
		}
	}

	/**
	 * Build the index in batches, each batch queuing the next.
	 *
	 * @param int $offset Where to continue.
	 */
	public static function build( int $offset = 0 ): void {
		$result = ( new Indexer() )->rebuild_batch( (int) $offset, 20 );

		if ( ! $result['done'] ) {
			self::queue_build( $result['next'] );
		}
	}

	/**
	 * Retention and housekeeping.
	 */
	public static function daily(): void {
		( new ConversationStore() )->purge_older_than( (int) Settings::get( 'retention_days', 90 ) );
		RateLimiter::prune();
		Support\Log::prune( (int) Settings::get( 'log_retention_days', 30 ) );
		Support\Queue::housekeeping();
	}
}

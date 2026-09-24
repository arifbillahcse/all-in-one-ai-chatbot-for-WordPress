<?php
/**
 * Fixed-window counters.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

use Softorio\AiAssistant\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per key in a fixed time window.
 *
 * Backed by a table rather than transients: a transient read-then-write lets
 * two simultaneous requests both see "one left", and the object cache most
 * shared hosts lack would be the only thing making transients atomic. The
 * INSERT … ON DUPLICATE KEY UPDATE here is one atomic statement.
 */
final class RateLimiter {

	/**
	 * Record a hit and report whether it is within the limit.
	 *
	 * @param string $key    Who is being limited.
	 * @param int    $max    Allowed hits per window.
	 * @param int    $window Window length in seconds.
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function hit( string $key, int $max, int $window ): array {
		global $wpdb;

		if ( $max <= 0 ) {
			return array(
				'allowed'     => true,
				'retry_after' => 0,
			);
		}

		$table  = Installer::tables()['limits'];
		$now    = time();
		$start  = $now - ( $now % $window );
		$bucket = hash( 'sha256', $key . '|' . $window . '|' . $start );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; atomic upsert has no WP API.
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, hits, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1',
				$table,
				$bucket,
				$start + $window
			)
		);

		$hits = false === $ok ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE bucket = %s', $table, $bucket ) );
		// phpcs:enable

		if ( 1 === wp_rand( 1, 50 ) ) {
			self::prune();
		}

		return array(
			'allowed'     => $hits <= $max,
			'retry_after' => max( 1, $start + $window - $now ),
		);
	}

	/**
	 * Delete expired buckets.
	 */
	public static function prune(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d', Installer::tables()['limits'], time() ) );
	}
}

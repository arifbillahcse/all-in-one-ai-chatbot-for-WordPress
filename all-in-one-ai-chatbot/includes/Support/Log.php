<?php
/**
 * Activity log.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

use Softorio\AiAssistant\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * A small, queryable log of what the plugin did in the background.
 *
 * Emails, webhooks and CRM syncs happen after the visitor's request has
 * finished, so when one fails nobody sees an error. This log is where the
 * site owner (or you, supporting them) finds out what happened and why.
 */
final class Log {

	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Write an info entry.
	 *
	 * @param string               $channel Area, e.g. "email", "webhook".
	 * @param string               $message What happened.
	 * @param array<string, mixed> $context Details.
	 */
	public static function info( string $channel, string $message, array $context = array() ): void {
		self::write( self::INFO, $channel, $message, $context );
	}

	/**
	 * Write a warning.
	 *
	 * @param string               $channel Area.
	 * @param string               $message What happened.
	 * @param array<string, mixed> $context Details.
	 */
	public static function warning( string $channel, string $message, array $context = array() ): void {
		self::write( self::WARNING, $channel, $message, $context );
	}

	/**
	 * Write an error.
	 *
	 * @param string               $channel Area.
	 * @param string               $message What happened.
	 * @param array<string, mixed> $context Details.
	 */
	public static function error( string $channel, string $message, array $context = array() ): void {
		self::write( self::ERROR, $channel, $message, $context );
	}

	/**
	 * Store an entry. Never throws: logging must not break what it describes.
	 *
	 * @param string               $level   Level.
	 * @param string               $channel Area.
	 * @param string               $message What happened.
	 * @param array<string, mixed> $context Details.
	 */
	private static function write( string $level, string $channel, string $message, array $context ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->insert(
			Installer::tables()['logs'],
			array(
				'level'      => $level,
				'channel'    => mb_substr( $channel, 0, 32 ),
				'message'    => mb_substr( $message, 0, 500 ),
				'context'    => array() === $context ? null : wp_json_encode( self::redact( $context ) ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Remove secrets from context before it is stored.
	 *
	 * @param array<string, mixed> $context Details.
	 * @return array<string, mixed>
	 */
	public static function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			} elseif ( is_string( $key ) && preg_match( '/key|secret|token|password|authorization/i', $key ) ) {
				$context[ $key ] = '***';
			}
		}

		return $context;
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @param int    $limit   Most rows.
	 * @param string $level   Only this level, or '' for all.
	 * @param string $channel Only this channel, or '' for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 200, string $level = '', string $channel = '' ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array( Installer::tables()['logs'] );

		if ( '' !== $level ) {
			$where[] = 'level = %s';
			$args[]  = $level;
		}

		if ( '' !== $channel ) {
			$where[] = 'channel = %s';
			$args[]  = $channel;
		}

		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; $where holds fixed fragments.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Channels that have entries, for the filter dropdown.
	 *
	 * @return array<int, string>
	 */
	public static function channels(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT channel FROM %i ORDER BY channel', Installer::tables()['logs'] ) ) );
	}

	/**
	 * Delete entries older than a number of days.
	 *
	 * @param int $days Days to keep.
	 */
	public static function prune( int $days ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Installer::tables()['logs'], gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) );
	}
}

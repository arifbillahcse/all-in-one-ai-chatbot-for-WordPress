<?php
/**
 * Background job queue.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

use Softorio\AiAssistant\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs slow work (emails, webhooks, CRM calls) after the visitor has their answer.
 *
 * A visitor should never wait on a mail server or a CRM's API, and a CRM
 * that is down for an hour should not lose the leads captured meanwhile. So
 * work is written to a table and run later, with retries:
 *
 *  - On PHP-FPM hosts it runs straight after the response is sent
 *    (fastcgi_finish_request), so notifications arrive within seconds.
 *  - Otherwise, and for retries, WP-Cron picks it up every minute.
 *
 * A failed job is retried with growing delays and kept as "failed" after the
 * last attempt, so it shows up in the activity log instead of vanishing.
 */
final class Queue {

	public const HOOK          = 'softorio_ai_queue';
	private const LOCK         = 'softorio_ai_queue_lock';
	private const MAX_ATTEMPTS = 5;

	/** Seconds to wait before retry N (1-based). */
	private const BACKOFF = array(
		1 => 60,
		2 => 300,
		3 => 1800,
		4 => 7200,
	);

	/**
	 * Job handlers by type.
	 *
	 * @var array<string, callable(array<string, mixed>): void>
	 */
	private static array $handlers = array();

	private static bool $run_on_shutdown = false;

	/**
	 * Hook the runner into cron.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_filter( 'cron_schedules', array( self::class, 'schedule_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute is intended.

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'softorio_ai_minute', self::HOOK );
		}
	}

	/**
	 * A one-minute cron interval.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedule_interval( array $schedules ): array {
		$schedules['softorio_ai_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (AI Chatbot queue)', 'all-in-one-ai-chatbot' ),
		);

		return $schedules;
	}

	/**
	 * Register what runs a job type. The handler throws to signal failure.
	 *
	 * @param string                                  $type    Job type.
	 * @param callable(array<string, mixed>): void $handler Handler.
	 */
	public static function register( string $type, callable $handler ): void {
		self::$handlers[ $type ] = $handler;
	}

	/**
	 * Add a job.
	 *
	 * @param string               $type    Job type.
	 * @param array<string, mixed> $payload Data for the handler.
	 * @param int                  $delay   Seconds before it may run.
	 * @return int Job id.
	 */
	public static function push( string $type, array $payload, int $delay = 0 ): int {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->insert(
			Installer::tables()['jobs'],
			array(
				'type'       => mb_substr( $type, 0, 64 ),
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'pending',
				'attempts'   => 0,
				'run_at'     => $now + max( 0, $delay ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( 0 === $delay && ! self::$run_on_shutdown && apply_filters( 'softorio_ai_queue_run_on_shutdown', true ) ) {
			self::$run_on_shutdown = true;
			add_action( 'shutdown', array( self::class, 'run_after_response' ), 100 );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Run due jobs once the response has gone to the browser.
	 */
	public static function run_after_response(): void {
		if ( ! function_exists( 'fastcgi_finish_request' ) ) {
			// Without FPM the visitor would wait for this work; leave it to cron.
			return;
		}

		fastcgi_finish_request();
		self::run();
	}

	/**
	 * Run due jobs, within a time budget. Only one runner at a time.
	 *
	 * @param int $budget Seconds to spend at most.
	 * @return int Jobs processed.
	 */
	public static function run( int $budget = 20 ): int {
		// add_option is atomic: only one process can create the lock row.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			$since = (int) get_option( self::LOCK );

			// A runner killed mid-way (timeout, fatal) must not block the queue forever.
			if ( $since > time() - 120 ) {
				return 0;
			}

			update_option( self::LOCK, time(), false );
		}

		$started   = time();
		$processed = 0;

		try {
			while ( time() - $started < $budget ) {
				$job = self::claim();

				if ( null === $job ) {
					break;
				}

				self::execute( $job );
				++$processed;
			}
		} finally {
			delete_option( self::LOCK );
		}

		return $processed;
	}

	/**
	 * Take the next due job.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function claim(): ?array {
		global $wpdb;

		$table = Installer::tables()['jobs'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' AND run_at <= %d ORDER BY run_at ASC, id ASC LIMIT 1", $table, time() ), ARRAY_A );

		if ( ! is_array( $job ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		// run_at doubles as "claimed at" while running, so housekeeping can
		// spot a job whose runner died.
		$wpdb->update(
			$table,
			array(
				'status' => 'running',
				'run_at' => time(),
			),
			array( 'id' => (int) $job['id'] ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		return $job;
	}

	/**
	 * Run one job and record the outcome.
	 *
	 * @param array<string, mixed> $job Job row.
	 */
	private static function execute( array $job ): void {
		global $wpdb;

		$table    = Installer::tables()['jobs'];
		$id       = (int) $job['id'];
		$type     = (string) $job['type'];
		$attempts = (int) $job['attempts'] + 1;
		$payload  = json_decode( (string) $job['payload'], true );
		$handler  = self::$handlers[ $type ] ?? null;

		try {
			if ( null === $handler ) {
				throw new \RuntimeException( 'No handler is registered for this job type (is its feature switched off?).' );
			}

			$handler( is_array( $payload ) ? $payload : array() );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		} catch ( \Throwable $e ) {
			$final = $attempts >= self::MAX_ATTEMPTS || null === $handler;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$wpdb->update(
				$table,
				array(
					'status'     => $final ? 'failed' : 'pending',
					'attempts'   => $attempts,
					'run_at'     => time() + ( self::BACKOFF[ $attempts ] ?? 3600 ),
					'last_error' => mb_substr( $e->getMessage(), 0, 1000 ),
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%d', '%s' ),
				array( '%d' )
			);

			$context = array(
				'job'   => $id,
				'error' => $e->getMessage(),
			);

			if ( $final ) {
				Log::error( 'queue', sprintf( 'Gave up on %1$s after %2$d attempts', $type, $attempts ), $context );
			} else {
				Log::warning( 'queue', sprintf( '%1$s failed (attempt %2$d), will retry', $type, $attempts ), $context );
			}
		}
	}

	/**
	 * Counts by status, for the dashboard.
	 *
	 * @return array{pending: int, failed: int}
	 */
	public static function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Installer::tables()['jobs'] ), ARRAY_A );

		$out = array(
			'pending' => 0,
			'failed'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			if ( 'running' === $row['status'] || 'pending' === $row['status'] ) {
				$out['pending'] += (int) $row['n'];
			} elseif ( 'failed' === $row['status'] ) {
				$out['failed'] += (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * Put failed jobs back in the queue (the "Retry failed" button).
	 */
	public static function retry_failed(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending', attempts = 0, run_at = %d WHERE status = 'failed'", Installer::tables()['jobs'], time() ) );
	}

	/**
	 * Release jobs stuck in "running" by a runner that died, and drop old failures.
	 */
	public static function housekeeping(): void {
		global $wpdb;

		$table = Installer::tables()['jobs'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending' WHERE status = 'running' AND run_at < %d", $table, time() - 600 ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status = 'failed' AND created_at < %s", $table, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}
}

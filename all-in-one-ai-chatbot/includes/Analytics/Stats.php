<?php
/**
 * Numbers for the Analytics screen and reports.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Analytics;

use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Knowledge\Tokenizer;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Chat analytics.
 *
 * Counts are grouped by the hour in SQL (SUBSTR of the stored UTC time,
 * which MySQL, MariaDB and SQLite all understand) and moved to the site's
 * timezone in PHP. That keeps the queries portable and cheap: at most 24
 * rows a day, however busy the site.
 *
 * Conversations are deleted after the retention period, so each finished
 * day is also saved as a small snapshot of totals (no personal data). Trends
 * reach back further than the chats themselves; details such as top
 * questions only cover the chats still kept.
 */
final class Stats {

	/** Metrics kept per day. */
	public const METRICS = array( 'conversations', 'messages', 'answers', 'unanswered', 'up', 'down', 'leads', 'live', 'cost' );

	/** Messages that are not questions. */
	private const CHATTER = array( 'thanks', 'thank', 'ok', 'okay', 'yes', 'yeah', 'no', 'bye', 'good', 'great', 'nice', 'cool', 'sure', 'ধন্যবাদ', 'আচ্ছা', 'ঠিক', 'হ্যাঁ', 'জি' );

	public const GAPS_DISMISSED = 'softorio_ai_gaps_dismissed';

	/**
	 * Tables.
	 *
	 * @var array<string, string>
	 */
	private array $t;

	private \DateTimeZone $tz;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->t  = Installer::tables();
		$this->tz = wp_timezone();
	}

	// ── Periods ─────────────────────────────────────────────────────────────

	/**
	 * Local dates of the last N days, oldest first, ending today (or $end).
	 *
	 * @param int                     $days Days.
	 * @param \DateTimeImmutable|null $end  Last day (local), default today.
	 * @return array<int, string> Y-m-d
	 */
	public function dates( int $days, ?\DateTimeImmutable $end = null ): array {
		$end   = ( $end ?? new \DateTimeImmutable( 'now', $this->tz ) )->setTimezone( $this->tz )->setTime( 0, 0 );
		$dates = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$dates[] = $end->modify( '-' . $i . ' days' )->format( 'Y-m-d' );
		}

		return $dates;
	}

	/**
	 * UTC "Y-m-d H:i:s" of local midnight on a date.
	 *
	 * @param string $date Local Y-m-d.
	 */
	private function utc_start( string $date ): string {
		return ( new \DateTimeImmutable( $date . ' 00:00:00', $this->tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Local date and hour of a UTC hour bucket ("2026-09-25 18").
	 *
	 * @param string $bucket UTC "Y-m-d H".
	 * @return array{0: string, 1: int, 2: int} Local Y-m-d, hour, weekday (0 = Sunday).
	 */
	private function local( string $bucket ): array {
		$time = ( new \DateTimeImmutable( $bucket . ':00:00', new \DateTimeZone( 'UTC' ) ) )->setTimezone( $this->tz );

		return array( $time->format( 'Y-m-d' ), (int) $time->format( 'G' ), (int) $time->format( 'w' ) );
	}

	// ── Daily series ────────────────────────────────────────────────────────

	/**
	 * Metrics per local day, from the chats still kept, or the saved
	 * snapshot for days whose chats were already deleted.
	 *
	 * @param int                     $days Days.
	 * @param \DateTimeImmutable|null $end  Last day.
	 * @return array<string, array<string, float|int>> date => metrics
	 */
	public function series( int $days, ?\DateTimeImmutable $end = null ): array {
		$dates  = $this->dates( $days, $end );
		$raw    = $this->raw( $dates[0], end( $dates ) );
		$kept   = (int) Settings::get( 'retention_days', 90 );
		$cutoff = $kept > 0 ? ( new \DateTimeImmutable( 'now', $this->tz ) )->modify( '-' . ( $kept - 1 ) . ' days' )->format( 'Y-m-d' ) : '0000-00-00';
		$saved  = $this->snapshots( $dates[0], end( $dates ) );
		$out    = array();

		foreach ( $dates as $date ) {
			$out[ $date ] = $date < $cutoff && isset( $saved[ $date ] ) ? $saved[ $date ] : ( $raw[ $date ] ?? self::zero() );
		}

		return $out;
	}

	/**
	 * Empty metrics.
	 *
	 * @return array<string, float|int>
	 */
	public static function zero(): array {
		$zero         = array_fill_keys( self::METRICS, 0 );
		$zero['cost'] = 0.0;

		return $zero;
	}

	/**
	 * Metrics per local day from the live tables.
	 *
	 * @param string $first First local date.
	 * @param string $last  Last local date.
	 * @return array<string, array<string, float|int>>
	 */
	private function raw( string $first, string $last ): array {
		global $wpdb;

		$from = $this->utc_start( $first );
		$to   = $this->utc_start( ( new \DateTimeImmutable( $last ) )->modify( '+1 day' )->format( 'Y-m-d' ) );
		$days = array();
		$add  = function ( string $bucket, string $metric, float $value ) use ( &$days ): void {
			[ $date ]                  = $this->local( $bucket );
			$days[ $date ]           ??= self::zero();
			$days[ $date ][ $metric ] += $value;
		};

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables; aggregate reads.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY h', $this->t['conversations'], $from, $to ), ARRAY_A ) as $row ) {
			$add( $row['h'], 'conversations', (float) $row['n'] );
		}

		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY h', $this->t['leads'], $from, $to ), ARRAY_A ) as $row ) {
			$add( $row['h'], 'leads', (float) $row['n'] );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT SUBSTR(created_at, 1, 13) AS h,
					SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS messages,
					SUM(CASE WHEN role = 'assistant' THEN 1 ELSE 0 END) AS answers,
					SUM(CASE WHEN role = 'assistant' AND unanswered = 1 THEN 1 ELSE 0 END) AS unanswered,
					SUM(CASE WHEN role = 'assistant' AND rating = 1 THEN 1 ELSE 0 END) AS up,
					SUM(CASE WHEN role = 'assistant' AND rating = -1 THEN 1 ELSE 0 END) AS down,
					SUM(cost) AS cost
				 FROM %i WHERE created_at >= %s AND created_at < %s GROUP BY h",
				$this->t['messages'],
				$from,
				$to
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			foreach ( array( 'messages', 'answers', 'unanswered', 'up', 'down', 'cost' ) as $metric ) {
				$add( $row['h'], $metric, (float) $row[ $metric ] );
			}
		}

		// A live chat counts once, on the day an agent first wrote in it.
		$live = $wpdb->get_results( $wpdb->prepare( "SELECT conversation_id AS c, MIN(SUBSTR(created_at, 1, 13)) AS h FROM %i WHERE role = 'agent' AND created_at >= %s AND created_at < %s GROUP BY conversation_id", $this->t['messages'], $from, $to ), ARRAY_A );
		// phpcs:enable

		foreach ( (array) $live as $row ) {
			$add( $row['h'], 'live', 1 );
		}

		foreach ( $days as $date => $metrics ) {
			foreach ( $metrics as $key => $value ) {
				$days[ $date ][ $key ] = 'cost' === $key ? round( (float) $value, 6 ) : (int) $value;
			}
		}

		return $days;
	}

	/**
	 * Saved daily snapshots.
	 *
	 * @param string $first First date.
	 * @param string $last  Last date.
	 * @return array<string, array<string, float|int>>
	 */
	private function snapshots( string $first, string $last ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, stats FROM %i WHERE day >= %s AND day <= %s', $this->t['daily'], $first, $last ), ARRAY_A );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$stats = json_decode( (string) $row['stats'], true );

			if ( is_array( $stats ) ) {
				$out[ (string) $row['day'] ] = array_merge( self::zero(), array_intersect_key( $stats, self::zero() ) );
			}
		}

		return $out;
	}

	/**
	 * Save snapshots of the last few finished days (daily cron).
	 *
	 * Re-saving is harmless, and covering three days means a site whose
	 * cron skipped a day (no traffic) loses nothing.
	 *
	 * @param int $days Finished days to save.
	 */
	public function snapshot_recent( int $days = 3 ): void {
		global $wpdb;

		$yesterday = ( new \DateTimeImmutable( 'now', $this->tz ) )->modify( '-1 day' );
		$now       = current_time( 'mysql', true );

		foreach ( $this->raw( $this->dates( $days, $yesterday )[0], $yesterday->format( 'Y-m-d' ) ) + array_fill_keys( $this->dates( $days, $yesterday ), self::zero() ) as $date => $stats ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; portable upsert.
			$wpdb->replace(
				$this->t['daily'],
				array(
					'day'        => $date,
					'stats'      => wp_json_encode( $stats ),
					'updated_at' => $now,
				),
				array( '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Totals and rates over a series.
	 *
	 * @param array<string, array<string, float|int>> $series Daily metrics.
	 * @return array<string, float|int|null>
	 */
	public static function totals( array $series ): array {
		$sum = self::zero();

		foreach ( $series as $day ) {
			foreach ( self::METRICS as $metric ) {
				$sum[ $metric ] += $day[ $metric ] ?? 0;
			}
		}

		$rated = $sum['up'] + $sum['down'];

		return $sum + array(
			'answered_rate'     => $sum['answers'] > 0 ? 1 - $sum['unanswered'] / $sum['answers'] : null,
			'satisfaction'      => $rated > 0 ? $sum['up'] / $rated : null,
			'conversion'        => $sum['conversations'] > 0 ? $sum['leads'] / $sum['conversations'] : null,
			'cost_per_chat'     => $sum['conversations'] > 0 ? $sum['cost'] / $sum['conversations'] : null,
			'messages_per_chat' => $sum['conversations'] > 0 ? $sum['messages'] / $sum['conversations'] : null,
		);
	}

	/**
	 * The last N days and the N days before, for "▲ 12%" comparisons.
	 *
	 * @param int $days Days.
	 * @return array{current: array<string, mixed>, previous: array<string, mixed>, series: array<string, array<string, float|int>>}
	 */
	public function period( int $days ): array {
		$series   = $this->series( $days );
		$previous = $this->series( $days, ( new \DateTimeImmutable( 'now', $this->tz ) )->modify( '-' . $days . ' days' ) );

		return array(
			'current'  => self::totals( $series ),
			'previous' => self::totals( $previous ),
			'series'   => $series,
		);
	}

	// ── Details (from the chats still kept) ─────────────────────────────────

	/**
	 * UTC start of the last N local days.
	 *
	 * @param int $days Days.
	 */
	private function since( int $days ): string {
		return $this->utc_start( $this->dates( $days )[0] );
	}

	/**
	 * Conversations by weekday and hour (site time).
	 *
	 * @param int $days Days.
	 * @return array<int, array<int, int>> [weekday 0 = Sunday][hour]
	 */
	public function heatmap( int $days ): array {
		global $wpdb;

		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY h', $this->t['conversations'], $this->since( $days ) ), ARRAY_A ) as $row ) {
			[ , $hour, $weekday ]       = $this->local( $row['h'] );
			$grid[ $weekday ][ $hour ] += (int) $row['n'];
		}

		return $grid;
	}

	/**
	 * What a question is about: its meaningful words, sorted, so "How much
	 * is delivery?" and "delivery how much" group together.
	 *
	 * @param string $text Question.
	 */
	public static function signature( string $text ): string {
		static $chatter = null;
		$chatter      ??= array_flip( self::CHATTER );

		$terms = array_values( array_unique( array_filter( Tokenizer::tokenize( $text ), static fn( string $t ): bool => ! isset( $chatter[ $t ] ) ) ) );

		if ( array() === $terms ) {
			return '';
		}

		sort( $terms );

		return implode( ' ', array_slice( $terms, 0, 10 ) );
	}

	/**
	 * Group texts by signature.
	 *
	 * @param array<int, array{text: string, conversation: int, at: string}> $items Texts, newest first.
	 * @param int                                                           $limit Groups.
	 * @return array<int, array{signature: string, count: int, example: string, conversation: int, last: string}>
	 */
	private static function group( array $items, int $limit ): array {
		$groups = array();

		foreach ( $items as $item ) {
			$signature = self::signature( $item['text'] );

			if ( '' === $signature ) {
				continue;
			}

			if ( ! isset( $groups[ $signature ] ) ) {
				$groups[ $signature ] = array(
					'signature'    => $signature,
					'count'        => 0,
					'forms'        => array(),
					'conversation' => $item['conversation'],
					'last'         => $item['at'],
				);
			}

			++$groups[ $signature ]['count'];
			$form                                   = mb_substr( trim( preg_replace( '/\s+/u', ' ', $item['text'] ) ?? '' ), 0, 160 );
			$groups[ $signature ]['forms'][ $form ] = ( $groups[ $signature ]['forms'][ $form ] ?? 0 ) + 1;
		}

		usort( $groups, static fn( array $a, array $b ): int => array( $b['count'], $b['last'] ) <=> array( $a['count'], $a['last'] ) );

		return array_map(
			static function ( array $g ): array {
				arsort( $g['forms'] );
				$g['example'] = (string) array_key_first( $g['forms'] );
				unset( $g['forms'] );

				return $g;
			},
			array_slice( $groups, 0, $limit )
		);
	}

	/**
	 * Most asked questions.
	 *
	 * @param int $days  Days.
	 * @param int $limit Groups.
	 * @return array<int, array<string, mixed>>
	 */
	public function top_questions( int $days, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT content AS text, conversation_id AS conversation, created_at AS at FROM %i WHERE role = 'user' AND created_at >= %s ORDER BY created_at DESC, id DESC LIMIT 5000", $this->t['messages'], $this->since( $days ) ), ARRAY_A );

		return self::group( array_map( array( self::class, 'item' ), (array) $rows ), $limit );
	}

	/**
	 * Questions the assistant could not answer: the knowledge gaps.
	 *
	 * @param int  $days      Days.
	 * @param int  $limit     Groups.
	 * @param bool $dismissed Include gaps the owner dismissed.
	 * @return array<int, array<string, mixed>>
	 */
	public function gaps( int $days, int $limit = 10, bool $dismissed = false ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT (SELECT q.content FROM %i q WHERE q.conversation_id = a.conversation_id AND q.role = 'user' AND q.id < a.id ORDER BY q.id DESC LIMIT 1) AS text,
					a.conversation_id AS conversation, a.created_at AS at
				 FROM %i a WHERE a.role = 'assistant' AND a.unanswered = 1 AND a.created_at >= %s ORDER BY a.created_at DESC, a.id DESC LIMIT 2000",
				$this->t['messages'],
				$this->t['messages'],
				$this->since( $days )
			),
			ARRAY_A
		);

		$groups = self::group( array_map( array( self::class, 'item' ), (array) $rows ), 200 );

		if ( ! $dismissed ) {
			$hidden = (array) get_option( self::GAPS_DISMISSED, array() );

			// A dismissed gap comes back if it is asked again afterwards.
			$groups = array_filter( $groups, static fn( array $g ): bool => ! isset( $hidden[ $g['signature'] ] ) || strtotime( $g['last'] . ' UTC' ) > (int) $hidden[ $g['signature'] ] );
		}

		return array_slice( array_values( $groups ), 0, $limit );
	}

	/**
	 * Hide a knowledge gap (until it is asked again).
	 *
	 * @param string $signature Gap signature.
	 */
	public static function dismiss_gap( string $signature ): void {
		$hidden               = (array) get_option( self::GAPS_DISMISSED, array() );
		$hidden[ $signature ] = time();

		// Keep the list small: forget the oldest dismissals.
		arsort( $hidden );
		update_option( self::GAPS_DISMISSED, array_slice( $hidden, 0, 500, true ), false );
	}

	/**
	 * Row to grouping item.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{text: string, conversation: int, at: string}
	 */
	private static function item( array $row ): array {
		return array(
			'text'         => (string) ( $row['text'] ?? '' ),
			'conversation' => (int) ( $row['conversation'] ?? 0 ),
			'at'           => (string) ( $row['at'] ?? '' ),
		);
	}

	/**
	 * Answers visitors rated 👎, newest first, with their question.
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return array<int, array{question: string, answer: string, conversation: int, at: string}>
	 */
	public function low_rated( int $days, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT (SELECT q.content FROM %i q WHERE q.conversation_id = a.conversation_id AND q.role = 'user' AND q.id < a.id ORDER BY q.id DESC LIMIT 1) AS question,
					a.content AS answer, a.conversation_id AS conversation, a.created_at AS at
				 FROM %i a WHERE a.role = 'assistant' AND a.rating = -1 AND a.created_at >= %s ORDER BY a.id DESC LIMIT %d",
				$this->t['messages'],
				$this->t['messages'],
				$this->since( $days ),
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $r ): array => array(
				'question'     => (string) $r['question'],
				'answer'       => (string) $r['answer'],
				'conversation' => (int) $r['conversation'],
				'at'           => (string) $r['at'],
			),
			(array) $rows
		);
	}

	/**
	 * Pages where chats start.
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return array<int, array{page: string, count: int}>
	 */
	public function top_pages( int $days, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT page_url AS page, COUNT(*) AS n FROM %i WHERE created_at >= %s AND page_url <> '' GROUP BY page_url ORDER BY n DESC LIMIT %d", $this->t['conversations'], $this->since( $days ), $limit ), ARRAY_A );

		return array_map(
			static fn( array $r ): array => array(
				'page'  => (string) $r['page'],
				'count' => (int) $r['n'],
			),
			(array) $rows
		);
	}

	/**
	 * Knowledge the assistant linked to most (its "Related pages").
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return array<int, array{title: string, url: string, count: int}>
	 */
	public function top_sources( int $days, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT sources FROM %i WHERE role = 'assistant' AND created_at >= %s AND sources IS NOT NULL AND sources <> '[]' ORDER BY id DESC LIMIT 5000", $this->t['messages'], $this->since( $days ) ) );
		$count = array();

		foreach ( $rows as $json ) {
			$sources = json_decode( (string) $json, true );
			$sources = is_array( $sources['sources'] ?? null ) ? $sources['sources'] : ( is_array( $sources ) && array_is_list( $sources ) ? $sources : array() );

			foreach ( $sources as $source ) {
				$url = (string) ( $source['url'] ?? '' );

				if ( '' === $url ) {
					continue;
				}

				$count[ $url ] ??= array(
					'title' => (string) ( $source['title'] ?? $url ),
					'url'   => $url,
					'count' => 0,
				);
				++$count[ $url ]['count'];
			}
		}

		usort( $count, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		return array_slice( $count, 0, $limit );
	}

	/**
	 * Live chat figures: chats with an agent and the average wait before
	 * one joined.
	 *
	 * @param int $days Days.
	 * @return array{chats: int, answered: int, missed: int, avg_wait: int|null}
	 */
	public function live( int $days ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT conversation_id AS c, sources, created_at FROM %i WHERE role = 'system' AND created_at >= %s ORDER BY id ASC LIMIT 20000", $this->t['messages'], $this->since( $days ) ), ARRAY_A );

		$waiting  = array();
		$waits    = array();
		$answered = 0;
		$missed   = 0;

		foreach ( (array) $rows as $row ) {
			$meta  = json_decode( (string) $row['sources'], true );
			$event = (string) ( $meta['live']['event'] ?? '' );
			$at    = strtotime( $row['created_at'] . ' UTC' );
			$c     = (int) $row['c'];

			if ( 'waiting' === $event ) {
				$waiting[ $c ] = $at;
			} elseif ( 'joined' === $event && isset( $waiting[ $c ] ) ) {
				$waits[] = $at - $waiting[ $c ];
				++$answered;
				unset( $waiting[ $c ] );
			} elseif ( 'timeout' === $event ) {
				++$missed;
				unset( $waiting[ $c ] );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$chats = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT conversation_id) FROM %i WHERE role = 'agent' AND created_at >= %s", $this->t['messages'], $this->since( $days ) ) );

		return array(
			'chats'    => $chats,
			'answered' => $answered,
			'missed'   => $missed,
			'avg_wait' => array() !== $waits ? (int) round( array_sum( $waits ) / count( $waits ) ) : null,
		);
	}
}

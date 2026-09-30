<?php
/**
 * Opening hours.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * When the site's human team is available.
 *
 * The widget decides "open or closed" in the visitor's browser, not on the
 * server: pages are usually served from a cache for hours, and a server-side
 * decision baked into a cached page would be wrong until the cache cleared.
 * So the server hands over the schedule and the site's UTC offset.
 */
final class BusinessHours {

	private const DAY_INDEX = array(
		'sun' => 0,
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
	);

	/**
	 * Whether business hours are in use.
	 */
	public static function enabled(): bool {
		return 'off' !== Settings::get( 'hours_mode', 'off' );
	}

	/**
	 * Ranges per weekday, keyed 0 (Sunday) to 6 (Saturday) like JavaScript's getDay().
	 *
	 * @return array<int, array<int, array{0: int, 1: int}>>
	 */
	public static function schedule(): array {
		$out = array_fill( 0, 7, array() );

		foreach ( array_keys( SettingsSchema::weekdays() ) as $day ) {
			$out[ self::DAY_INDEX[ $day ] ] = SettingsSchema::parse_hours( (string) Settings::get( 'hours_' . $day, '' ) );
		}

		return $out;
	}

	/**
	 * Whether the team is open at a moment.
	 *
	 * @param \DateTimeImmutable|null $at Moment; now when null.
	 */
	public static function is_open( ?\DateTimeImmutable $at = null ): bool {
		$at      = ( $at ?? new \DateTimeImmutable( 'now' ) )->setTimezone( wp_timezone() );
		$minutes = (int) $at->format( 'G' ) * 60 + (int) $at->format( 'i' );

		foreach ( self::schedule()[ (int) $at->format( 'w' ) ] as [ $start, $end ] ) {
			if ( $minutes >= $start && $minutes < $end ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * When the team is next open, as site-local time, within a week.
	 *
	 * @param \DateTimeImmutable|null $at From this moment; now when null.
	 */
	public static function next_open( ?\DateTimeImmutable $at = null ): ?\DateTimeImmutable {
		$at       = ( $at ?? new \DateTimeImmutable( 'now' ) )->setTimezone( wp_timezone() );
		$schedule = self::schedule();

		for ( $offset = 0; $offset <= 7; $offset++ ) {
			$day      = $at->modify( '+' . $offset . ' days' );
			$midnight = $day->setTime( 0, 0 );

			foreach ( $schedule[ (int) $day->format( 'w' ) ] as [ $start ] ) {
				$candidate = $midnight->modify( '+' . $start . ' minutes' );

				if ( $candidate > $at ) {
					return $candidate;
				}
			}
		}

		return null;
	}

	/**
	 * Human-readable weekly schedule, e.g. "Mon–Thu 09:00-18:00; Sat 10:00-16:00".
	 */
	public static function summary(): string {
		$lines = array();

		foreach ( SettingsSchema::weekdays() as $day => $label ) {
			$hours   = (string) Settings::get( 'hours_' . $day, '' );
			$lines[] = $label . ': ' . ( '' !== $hours ? $hours : 'closed' );
		}

		return implode( '; ', $lines );
	}

	/**
	 * What the widget needs to decide "open or closed" itself.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function widget_config(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}

		return array(
			'mode'     => (string) Settings::get( 'hours_mode', 'off' ),
			'days'     => self::schedule(),
			// Minutes east of UTC right now. Close enough across a cached
			// page's lifetime; sites with DST shift by an hour at most for a
			// few hours twice a year.
			'offset'   => (int) ( wp_timezone()->getOffset( new \DateTimeImmutable( 'now' ) ) / 60 ),
			'message'  => (string) Settings::get( 'hours_offline_message', '' ),
			'dayNames' => self::day_names(),
		);
	}

	/**
	 * Short, translated weekday names, Sunday first.
	 *
	 * @return array<int, string>
	 */
	private static function day_names(): array {
		global $wp_locale;

		$names = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$names[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $i ) ) : gmdate( 'D', strtotime( "Sunday +$i days" ) );
		}

		return $names;
	}

	/**
	 * A sentence for the system prompt about the team's availability now.
	 */
	public static function prompt_line(): string {
		if ( ! self::enabled() ) {
			return '';
		}

		if ( self::is_open() ) {
			return 'The human support team is available right now (business hours: ' . self::summary() . ').';
		}

		$next = self::next_open();

		return 'The human support team is offline right now' . ( null !== $next ? ' and is back ' . $next->format( 'l H:i' ) : '' ) . ' (business hours: ' . self::summary() . '). If the visitor wants a person, say when the team is back and offer to take their details.';
	}
}

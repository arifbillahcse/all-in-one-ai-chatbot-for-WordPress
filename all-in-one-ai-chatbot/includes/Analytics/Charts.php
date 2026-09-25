<?php
/**
 * SVG charts.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Small charts drawn as inline SVG on the server.
 *
 * No chart library and no CDN: nothing extra to load in WP Admin, nothing
 * sent to third parties, and the charts also work in the report email
 * preview. Every chart carries an accessible label and a data table.
 */
final class Charts {

	private const W = 760;
	private const H = 220;
	private const PAD_L = 44;
	private const PAD_B = 26;
	private const PAD_T = 12;

	/**
	 * Bars, with an optional line on the same scale.
	 *
	 * @param array<string, float|int> $bars   Label => value.
	 * @param array<string, float|int> $line   Label => value (optional).
	 * @param array<string, string>    $names  bars, line: legend names.
	 * @param string                   $format number|money.
	 */
	public static function bars( array $bars, array $line = array(), array $names = array(), string $format = 'number' ): string {
		$labels = array_keys( $bars );
		$count  = max( 1, count( $labels ) );
		$max    = max( 1e-9, (float) max( array_merge( array_values( $bars ), array_values( $line ), array( 0 ) ) ) );
		$max    = self::nice( $max, $format );
		$plot_w = self::W - self::PAD_L - 8;
		$plot_h = self::H - self::PAD_T - self::PAD_B;
		$slot   = $plot_w / $count;
		$bar_w  = max( 1.5, min( 28, $slot * 0.7 ) );
		$y      = static fn( float $v ): float => self::PAD_T + $plot_h - ( $v / $max ) * $plot_h;

		$svg = '';

		// Grid and y-axis labels at 0, ½ and the top.
		foreach ( array( 0, 0.5, 1 ) as $f ) {
			$gy   = $y( $max * $f );
			$svg .= sprintf( '<line x1="%1$d" x2="%2$d" y1="%3$.1f" y2="%3$.1f" class="sai-grid"/>', self::PAD_L, self::W - 8, $gy );
			$svg .= sprintf( '<text x="%1$d" y="%2$.1f" class="sai-axis" text-anchor="end">%3$s</text>', self::PAD_L - 6, $gy + 4, esc_html( self::fmt( $max * $f, $format ) ) );
		}

		$every = max( 1, (int) ceil( $count / 10 ) );

		foreach ( $labels as $i => $label ) {
			$value = (float) $bars[ $label ];
			$x     = self::PAD_L + $slot * $i + ( $slot - $bar_w ) / 2;
			$top   = $y( $value );

			$svg .= sprintf(
				'<rect x="%1$.1f" y="%2$.1f" width="%3$.1f" height="%4$.1f" rx="2" class="sai-bar"><title>%5$s</title></rect>',
				$x,
				$top,
				$bar_w,
				max( 0, self::PAD_T + $plot_h - $top ),
				esc_html( self::day( $label ) . ': ' . self::fmt( $value, $format ) . ( isset( $line[ $label ] ) ? ' · ' . ( $names['line'] ?? '' ) . ': ' . self::fmt( (float) $line[ $label ], $format ) : '' ) )
			);

			if ( 0 === $i % $every ) {
				$svg .= sprintf( '<text x="%1$.1f" y="%2$d" class="sai-axis" text-anchor="middle">%3$s</text>', $x + $bar_w / 2, self::H - 8, esc_html( self::day( $label, true ) ) );
			}
		}

		if ( array() !== $line ) {
			$points = array();

			foreach ( $labels as $i => $label ) {
				$points[] = sprintf( '%.1f,%.1f', self::PAD_L + $slot * $i + $slot / 2, $y( (float) ( $line[ $label ] ?? 0 ) ) );
			}

			$svg .= '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" class="sai-line"/>';

			foreach ( $points as $point ) {
				[ $px, $py ] = explode( ',', $point );
				$svg        .= sprintf( '<circle cx="%s" cy="%s" r="2.5" class="sai-dot"/>', esc_attr( $px ), esc_attr( $py ) );
			}
		}

		$summary = ( $names['bars'] ?? '' ) . ': ' . self::fmt( array_sum( $bars ), $format ) . ( array() !== $line ? ', ' . ( $names['line'] ?? '' ) . ': ' . self::fmt( array_sum( $line ), $format ) : '' );

		return self::wrap( $svg, $summary ) . self::legend( $names, array() !== $line ) . self::table( $bars, $line, $names, $format );
	}

	/**
	 * Weekday × hour grid.
	 *
	 * @param array<int, array<int, int>> $grid      [weekday 0 = Sunday][hour].
	 * @param array<int, string>          $day_names Short names, Sunday first.
	 * @param int                         $start     First weekday shown (1 = Monday).
	 */
	public static function heatmap( array $grid, array $day_names, int $start = 1 ): string {
		$max  = max( 1, (int) max( array_map( 'max', $grid ) ) );
		$cell = 26;
		$left = 40;
		$w    = $left + 24 * $cell;
		$h    = 7 * $cell + 22;
		$svg  = '';

		for ( $row = 0; $row < 7; $row++ ) {
			$day  = ( $start + $row ) % 7;
			$y    = $row * $cell;
			$svg .= sprintf( '<text x="%1$d" y="%2$d" class="sai-axis" text-anchor="end">%3$s</text>', $left - 6, $y + 17, esc_html( $day_names[ $day ] ?? '' ) );

			for ( $hour = 0; $hour < 24; $hour++ ) {
				$n     = (int) ( $grid[ $day ][ $hour ] ?? 0 );
				$alpha = 0 === $n ? 0.06 : 0.18 + 0.82 * $n / $max;

				$svg .= sprintf(
					'<rect x="%1$d" y="%2$d" width="%3$d" height="%3$d" rx="3" class="sai-heat" fill-opacity="%4$.2f"><title>%5$s</title></rect>',
					$left + $hour * $cell + 1,
					$y + 1,
					$cell - 2,
					$alpha,
					esc_html( sprintf( '%s %02d:00 — %d', $day_names[ $day ] ?? '', $hour, $n ) )
				);
			}
		}

		foreach ( array( 0, 6, 12, 18, 23 ) as $hour ) {
			$svg .= sprintf( '<text x="%1$d" y="%2$d" class="sai-axis" text-anchor="middle">%3$02d</text>', $left + $hour * $cell + $cell / 2, $h - 4, $hour );
		}

		return sprintf(
			'<svg class="sai-chart sai-heatmap" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s">%4$s</svg>',
			$w,
			$h,
			esc_attr__( 'Conversations by day of the week and hour', 'all-in-one-ai-chatbot' ),
			$svg
		);
	}

	/**
	 * A round top for the y-axis.
	 *
	 * @param float  $max    Largest value.
	 * @param string $format number|money.
	 */
	private static function nice( float $max, string $format ): float {
		if ( 'number' === $format && $max < 5 ) {
			return 5;
		}

		$magnitude = 10 ** floor( log10( $max ) );

		foreach ( array( 1, 2, 2.5, 5, 10 ) as $step ) {
			if ( $step * $magnitude >= $max ) {
				return $step * $magnitude;
			}
		}

		return 10 * $magnitude;
	}

	/**
	 * Format a value.
	 *
	 * @param float  $value  Value.
	 * @param string $format number|money.
	 */
	public static function fmt( float $value, string $format = 'number' ): string {
		if ( 'money' === $format ) {
			return '$' . number_format_i18n( $value, $value > 0 && $value < 1 ? 3 : 2 );
		}

		return number_format_i18n( $value, abs( $value - round( $value ) ) > 0.001 ? 1 : 0 );
	}

	/**
	 * "Sep 25" (or "25" for axis labels).
	 *
	 * @param string $date  Y-m-d.
	 * @param bool   $short Day of month only.
	 */
	private static function day( string $date, bool $short = false ): string {
		$time = strtotime( $date . ' 12:00:00' );

		return false === $time ? $date : wp_date( $short ? 'j M' : get_option( 'date_format', 'M j' ), $time, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * SVG wrapper.
	 *
	 * @param string $inner   Content.
	 * @param string $summary Accessible summary.
	 */
	private static function wrap( string $inner, string $summary ): string {
		return sprintf( '<svg class="sai-chart" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s">%4$s</svg>', self::W, self::H, esc_attr( $summary ), $inner );
	}

	/**
	 * Legend.
	 *
	 * @param array<string, string> $names Names.
	 * @param bool                  $line  Has a line.
	 */
	private static function legend( array $names, bool $line ): string {
		$html = '<p class="sai-legend"><span class="sai-key sai-key-bar"></span> ' . esc_html( $names['bars'] ?? '' );

		if ( $line ) {
			$html .= ' <span class="sai-key sai-key-line"></span> ' . esc_html( $names['line'] ?? '' );
		}

		return $html . '</p>';
	}

	/**
	 * The numbers behind a chart, for screen readers and copying.
	 *
	 * @param array<string, float|int> $bars   Bars.
	 * @param array<string, float|int> $line   Line.
	 * @param array<string, string>    $names  Names.
	 * @param string                   $format Format.
	 */
	private static function table( array $bars, array $line, array $names, string $format ): string {
		$rows = '';

		foreach ( $bars as $label => $value ) {
			$rows .= '<tr><td>' . esc_html( self::day( (string) $label ) ) . '</td><td>' . esc_html( self::fmt( (float) $value, $format ) ) . '</td>'
				. ( array() !== $line ? '<td>' . esc_html( self::fmt( (float) ( $line[ $label ] ?? 0 ), $format ) ) . '</td>' : '' ) . '</tr>';
		}

		return '<details class="sai-chart-data"><summary>' . esc_html__( 'Show the numbers', 'all-in-one-ai-chatbot' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Day', 'all-in-one-ai-chatbot' ) . '</th><th>' . esc_html( $names['bars'] ?? '' ) . '</th>'
			. ( array() !== $line ? '<th>' . esc_html( $names['line'] ?? '' ) . '</th>' : '' ) . '</tr></thead><tbody>' . $rows . '</tbody></table></details>';
	}
}

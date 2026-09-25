<?php
/**
 * Scheduled report emails.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Analytics;

use Softorio\AiAssistant\Admin\Menu;
use Softorio\AiAssistant\Notify\Notifier;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * A weekly or monthly summary by email: the numbers with their change,
 * what visitors asked most, and the questions the assistant could not
 * answer (with a link to add the answer).
 *
 * The daily cron calls maybe_send(); the first run in a new week or month
 * sends the report for the period just finished.
 */
final class Report {

	private const LAST = 'softorio_ai_report_last';

	/**
	 * Send if a new week/month has started since the last report.
	 */
	public static function maybe_send(): bool {
		$frequency = (string) Settings::get( 'report_frequency', 'off' );

		if ( ! in_array( $frequency, array( 'weekly', 'monthly' ), true ) ) {
			return false;
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		$key = 'weekly' === $frequency ? $now->format( 'o-\WW' ) : $now->format( 'Y-m' );

		if ( get_option( self::LAST ) === $key ) {
			return false;
		}

		update_option( self::LAST, $key, false );

		$report = self::build( 'weekly' === $frequency ? 7 : 30, true );

		Queue::push( 'email.send', array( 'to' => implode( ',', self::recipients() ) ) + $report );

		return true;
	}

	/**
	 * Who gets the report.
	 *
	 * @return array<int, string>
	 */
	public static function recipients(): array {
		$list = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) Settings::get( 'report_recipients', '' ) ) ?: array() ), 'is_email' );

		return array() !== $list ? array_values( array_unique( $list ) ) : array( Notifier::owner_email() );
	}

	/**
	 * Send a report right now (the "Send a report now" button).
	 */
	public static function send_now(): string {
		$frequency = (string) Settings::get( 'report_frequency', 'off' );
		$report    = self::build( 'monthly' === $frequency ? 30 : 7, false );
		$to        = self::recipients();

		Notifier::send_email( array( 'to' => implode( ',', $to ) ) + $report );

		/* translators: %s: email addresses */
		return sprintf( __( 'Report sent to %s.', 'all-in-one-ai-chatbot' ), implode( ', ', $to ) );
	}

	/**
	 * Compose the report.
	 *
	 * @param int  $days     Period length.
	 * @param bool $finished Report on the days up to yesterday (a closed period) rather than up to today.
	 * @return array{subject: string, html: string}
	 */
	public static function build( int $days, bool $finished = false ): array {
		$stats    = new Stats();
		$end      = new \DateTimeImmutable( $finished ? 'yesterday' : 'now', wp_timezone() );
		$series   = $stats->series( $days, $end );
		$previous = $stats->series( $days, $end->modify( '-' . $days . ' days' ) );
		$cur      = Stats::totals( $series );
		$prev     = Stats::totals( $previous );
		$site     = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$dates    = array_keys( $series );

		$subject = sprintf(
			/* translators: 1: site name, 2: first day, 3: last day */
			__( '[%1$s] Chatbot report: %2$s – %3$s', 'all-in-one-ai-chatbot' ),
			$site,
			wp_date( 'M j', (int) strtotime( $dates[0] . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) ),
			wp_date( 'M j', (int) strtotime( end( $dates ) . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) )
		);

		$kpis = array(
			array( __( 'Conversations', 'all-in-one-ai-chatbot' ), $cur['conversations'], $prev['conversations'], 'number', true ),
			array( __( 'Leads', 'all-in-one-ai-chatbot' ), $cur['leads'], $prev['leads'], 'number', true ),
			array( __( 'Answered by the AI', 'all-in-one-ai-chatbot' ), $cur['answered_rate'], $prev['answered_rate'], 'percent', true ),
			array( __( 'Satisfaction', 'all-in-one-ai-chatbot' ), $cur['satisfaction'], $prev['satisfaction'], 'percent', true ),
			array( __( 'Live chats', 'all-in-one-ai-chatbot' ), $cur['live'], $prev['live'], 'number', true ),
			array( __( 'AI cost', 'all-in-one-ai-chatbot' ), $cur['cost'], $prev['cost'], 'money', false ),
		);

		$cells = array();

		foreach ( $kpis as [ $label, $value, $before, $format, $up_is_good ] ) {
			$cells[] = '<td style="padding:10px;border:1px solid #e5e7eb;border-radius:8px;width:33%;vertical-align:top">'
				. '<div style="font-size:12px;color:#6b7280">' . esc_html( $label ) . '</div>'
				. '<div style="font-size:22px;font-weight:700;margin:2px 0">' . esc_html( self::value( $value, $format ) ) . '</div>'
				. self::change( $value, $before, $up_is_good, $format ) . '</td>';
		}

		$grid = '<table style="width:100%;border-collapse:separate;border-spacing:6px">';

		foreach ( array_chunk( $cells, 3 ) as $row ) {
			$grid .= '<tr>' . implode( '', $row ) . '</tr>';
		}

		$grid .= '</table>';

		$html = '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of days */
				_n( 'How your AI assistant did in the last %d day, compared with the day before.', 'How your AI assistant did in the last %d days, compared with the period before.', $days, 'all-in-one-ai-chatbot' ),
				$days
			)
		) . '</p>' . $grid;

		$questions = $stats->top_questions( $days, 5 );

		if ( array() !== $questions ) {
			$html .= '<h3 style="font-size:15px;margin:24px 0 8px">' . esc_html__( 'What visitors asked most', 'all-in-one-ai-chatbot' ) . '</h3><ol style="padding-left:20px;margin:0">';

			foreach ( $questions as $q ) {
				$html .= '<li style="margin:0 0 4px">' . esc_html( $q['example'] ) . ' <span style="color:#6b7280">×' . (int) $q['count'] . '</span></li>';
			}

			$html .= '</ol>';
		}

		$gaps = $stats->gaps( $days, 5 );

		if ( array() !== $gaps ) {
			$html .= '<h3 style="font-size:15px;margin:24px 0 8px">' . esc_html__( 'Questions the assistant could not answer', 'all-in-one-ai-chatbot' ) . '</h3>'
				. '<p style="margin:0 0 8px;color:#6b7280">' . esc_html__( 'Add a Knowledge Article for each, and the assistant will answer next time.', 'all-in-one-ai-chatbot' ) . '</p><ol style="padding-left:20px;margin:0">';

			foreach ( $gaps as $g ) {
				$html .= '<li style="margin:0 0 6px">' . esc_html( $g['example'] ) . ' <span style="color:#6b7280">×' . (int) $g['count'] . '</span> — <a href="' . esc_url( self::add_answer_url( $g['example'] ) ) . '">' . esc_html__( 'Add the answer', 'all-in-one-ai-chatbot' ) . '</a></li>';
			}

			$html .= '</ol>';
		}

		$html .= sprintf(
			'<p style="margin-top:24px"><a href="%s" style="background:#2563eb;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">%s</a></p>',
			esc_url( Menu::url( 'analytics', array( 'days' => $days ) ) ),
			esc_html__( 'Open Analytics', 'all-in-one-ai-chatbot' )
		);

		$html .= '<p style="font-size:12px;color:#9ca3af">' . esc_html__( 'Change or stop this report under AI Chatbot → Settings → Notifications.', 'all-in-one-ai-chatbot' ) . '</p>';

		return array(
			'subject' => $subject,
			'html'    => '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.5;color:#111827;max-width:640px"><h2 style="font-size:18px;margin:0 0 12px">' . esc_html( $subject ) . '</h2>' . $html . '</div>',
		);
	}

	/**
	 * "New Knowledge Article" with the question as its title.
	 *
	 * @param string $question Question.
	 */
	public static function add_answer_url( string $question ): string {
		return add_query_arg(
			array(
				'post_type'  => \Softorio\AiAssistant\PostTypes::DOC,
				'post_title' => rawurlencode( mb_substr( $question, 0, 150 ) ),
			),
			admin_url( 'post-new.php' )
		);
	}

	/**
	 * Format a KPI value.
	 *
	 * @param float|int|null $value  Value.
	 * @param string         $format number|percent|money.
	 */
	public static function value( $value, string $format ): string {
		if ( null === $value ) {
			return '—';
		}

		return match ( $format ) {
			'percent' => number_format_i18n( 100 * (float) $value ) . '%',
			'money'   => Charts::fmt( (float) $value, 'money' ),
			default   => number_format_i18n( (float) $value ),
		};
	}

	/**
	 * "▲ 12%" in green or red.
	 *
	 * @param float|int|null $now        Current.
	 * @param float|int|null $before     Previous.
	 * @param bool           $up_is_good Whether a rise is good.
	 * @param string         $format     For "percent" values the change is in points (67% → 72% is "▲ 5 pts", not "▲ 7%").
	 */
	public static function change( $now, $before, bool $up_is_good, string $format = 'number' ): string {
		if ( null === $now || null === $before ) {
			return '<div style="font-size:12px;color:#9ca3af">&nbsp;</div>';
		}

		if ( 'percent' === $format ) {
			$points = ( (float) $now - (float) $before ) * 100;

			if ( abs( $points ) < 0.5 ) {
				return '<div style="font-size:12px;color:#6b7280">= ' . esc_html__( 'no change', 'all-in-one-ai-chatbot' ) . '</div>';
			}

			/* translators: %s: number of percentage points */
			$label = sprintf( __( '%s pts', 'all-in-one-ai-chatbot' ), number_format_i18n( abs( $points ) ) );

			return sprintf( '<div style="font-size:12px;color:%1$s">%2$s %3$s</div>', ( $points > 0 ) === $up_is_good ? '#15803d' : '#b91c1c', $points > 0 ? '▲' : '▼', esc_html( $label ) );
		}

		if ( 0.0 === (float) $before ) {
			return '<div style="font-size:12px;color:#9ca3af">&nbsp;</div>';
		}

		$pct = ( (float) $now - (float) $before ) / abs( (float) $before ) * 100;

		if ( abs( $pct ) < 0.5 ) {
			return '<div style="font-size:12px;color:#6b7280">= ' . esc_html__( 'no change', 'all-in-one-ai-chatbot' ) . '</div>';
		}

		$good  = ( $pct > 0 ) === $up_is_good;
		$color = $good ? '#15803d' : '#b91c1c';

		return sprintf( '<div style="font-size:12px;color:%1$s">%2$s %3$s%%</div>', $color, $pct > 0 ? '▲' : '▼', esc_html( number_format_i18n( abs( $pct ) ) ) );
	}
}

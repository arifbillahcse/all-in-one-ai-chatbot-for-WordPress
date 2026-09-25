<?php
/**
 * Analytics screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Analytics\Charts;
use Softorio\AiAssistant\Analytics\Report;
use Softorio\AiAssistant\Analytics\Stats;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Trends, what visitors ask, what the assistant could not answer, and
 * when people chat.
 */
final class AnalyticsPage {

	public const RANGES = array( 7, 30, 90, 365 );

	private const DISMISS = 'softorio_ai_dismiss_gap';
	private const EXPORT  = 'softorio_ai_export_stats';

	/**
	 * Hook the form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::DISMISS, array( self::class, 'dismiss' ) );
		add_action( 'admin_post_' . self::EXPORT, array( self::class, 'export' ) );
	}

	/**
	 * The chosen range.
	 */
	private static function days(): int {
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.

		return in_array( $days, self::RANGES, true ) ? $days : 30;
	}

	/**
	 * Hide a knowledge gap.
	 */
	public static function dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::DISMISS );

		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';

		if ( '' !== $signature ) {
			Stats::dismiss_gap( $signature );
		}

		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;

		wp_safe_redirect( Menu::url( 'analytics', array( 'days' => $days ) ) . '#sai-gaps' );
		exit;
	}

	/**
	 * Daily numbers as CSV.
	 */
	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::EXPORT );

		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$days = in_array( $days, self::RANGES, true ) ? $days : 30;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="chatbot-stats-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download.
		fputcsv( $out, array_merge( array( 'date' ), Stats::METRICS ), ',', '"', '' );

		foreach ( ( new Stats() )->series( $days ) as $date => $metrics ) {
			fputcsv( $out, array_merge( array( $date ), array_map( static fn( string $m ) => $metrics[ $m ], Stats::METRICS ) ), ',', '"', '' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download.
		exit;
	}

	/**
	 * A small POST button.
	 *
	 * @param string               $action Action.
	 * @param string               $label  Text.
	 * @param array<string, mixed> $fields Hidden fields.
	 * @param string               $css_class  Class.
	 */
	private static function button( string $action, string $label, array $fields, string $css_class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sai-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$days   = self::days();
		$stats  = new Stats();
		$period = $stats->period( $days );
		$cur    = $period['current'];
		$prev   = $period['previous'];
		$series = $period['series'];
		$detail = min( $days, max( 1, (int) Settings::get( 'retention_days', 90 ) ) );
		$live   = $stats->live( $detail );

		$kpis = array(
			array( __( 'Conversations', 'all-in-one-ai-chatbot' ), $cur['conversations'], $prev['conversations'], 'number', true, sprintf( /* translators: %s: number */ __( '%s messages from visitors', 'all-in-one-ai-chatbot' ), number_format_i18n( (int) $cur['messages'] ) ) ),
			array( __( 'Answered by the AI', 'all-in-one-ai-chatbot' ), $cur['answered_rate'], $prev['answered_rate'], 'percent', true, sprintf( /* translators: %s: number */ __( '%s questions without an answer', 'all-in-one-ai-chatbot' ), number_format_i18n( (int) $cur['unanswered'] ) ) ),
			array( __( 'Satisfaction', 'all-in-one-ai-chatbot' ), $cur['satisfaction'], $prev['satisfaction'], 'percent', true, sprintf( /* translators: 1: thumbs up, 2: thumbs down */ __( '👍 %1$s · 👎 %2$s', 'all-in-one-ai-chatbot' ), number_format_i18n( (int) $cur['up'] ), number_format_i18n( (int) $cur['down'] ) ) ),
			array( __( 'Leads', 'all-in-one-ai-chatbot' ), $cur['leads'], $prev['leads'], 'number', true, null !== $cur['conversion'] ? sprintf( /* translators: %s: percentage */ __( '%s of conversations', 'all-in-one-ai-chatbot' ), Report::value( $cur['conversion'], 'percent' ) ) : '' ),
			array( __( 'Live chats', 'all-in-one-ai-chatbot' ), $cur['live'], $prev['live'], 'number', true, null !== $live['avg_wait'] ? sprintf( /* translators: %s: duration */ __( 'average wait %s', 'all-in-one-ai-chatbot' ), self::duration( $live['avg_wait'] ) ) . ( $live['missed'] ? ' · ' . sprintf( /* translators: %d: number */ __( '%d missed', 'all-in-one-ai-chatbot' ), $live['missed'] ) : '' ) : '' ),
			array( __( 'AI cost (estimated)', 'all-in-one-ai-chatbot' ), $cur['cost'], $prev['cost'], 'money', false, null !== $cur['cost_per_chat'] ? sprintf( /* translators: %s: amount */ __( '%s per conversation', 'all-in-one-ai-chatbot' ), Charts::fmt( (float) $cur['cost_per_chat'], 'money' ) ) : '' ),
		);

		$range_labels = array(
			7   => __( '7 days', 'all-in-one-ai-chatbot' ),
			30  => __( '30 days', 'all-in-one-ai-chatbot' ),
			90  => __( '90 days', 'all-in-one-ai-chatbot' ),
			365 => __( '12 months', 'all-in-one-ai-chatbot' ),
		);

		$questions = $stats->top_questions( $detail, 10 );
		$gaps      = $stats->gaps( $detail, 10 );
		$low       = $stats->low_rated( $detail, 5 );
		$pages     = $stats->top_pages( $detail, 8 );
		$sources   = $stats->top_sources( $detail, 8 );

		global $wp_locale;
		$day_names = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$day_names[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $i ) ) : gmdate( 'D', strtotime( "Sunday +$i days" ) );
		}

		// 12 months of daily bars is too dense: show weeks.
		$chart_series = 365 === $days ? self::weekly( $series ) : $series;

		$conversations_chart = Charts::bars(
			array_map( static fn( array $d ) => $d['conversations'], $chart_series ),
			array_map( static fn( array $d ) => $d['leads'], $chart_series ),
			array(
				'bars' => __( 'Conversations', 'all-in-one-ai-chatbot' ),
				'line' => __( 'Leads', 'all-in-one-ai-chatbot' ),
			)
		);
		$cost_chart          = Charts::bars( array_map( static fn( array $d ) => $d['cost'], $chart_series ), array(), array( 'bars' => __( 'AI cost', 'all-in-one-ai-chatbot' ) ), 'money' );
		?>
		<div class="wrap sai-admin sai-analytics">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Analytics', 'all-in-one-ai-chatbot' ); ?></h1>
			<?php self::button( self::EXPORT, __( 'Export CSV', 'all-in-one-ai-chatbot' ), array( 'days' => $days ), 'page-title-action' ); ?>

			<nav class="sai-ranges" aria-label="<?php esc_attr_e( 'Period', 'all-in-one-ai-chatbot' ); ?>">
				<?php foreach ( $range_labels as $value => $label ) : ?>
					<a href="<?php echo esc_url( Menu::url( 'analytics', array( 'days' => $value ) ) ); ?>" class="<?php echo $value === $days ? 'is-current' : ''; ?>" <?php echo $value === $days ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="sai-kpis">
				<?php foreach ( $kpis as [ $label, $value, $before, $format, $up_is_good, $note ] ) : ?>
					<div class="sai-kpi">
						<div class="sai-kpi-label"><?php echo esc_html( $label ); ?></div>
						<div class="sai-kpi-value"><?php echo esc_html( Report::value( $value, $format ) ); ?></div>
						<?php echo Report::change( $value, $before, $up_is_good, $format ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
						<?php if ( '' !== $note ) : ?>
							<div class="sai-kpi-note"><?php echo esc_html( $note ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description sai-compare">
				<?php
				/* translators: %d: number of days */
				echo esc_html( sprintf( __( 'Changes compare with the %d days before.', 'all-in-one-ai-chatbot' ), $days ) );
				?>
			</p>

			<div class="sai-card">
				<h2><?php echo esc_html( 365 === $days ? __( 'Conversations and leads per week', 'all-in-one-ai-chatbot' ) : __( 'Conversations and leads per day', 'all-in-one-ai-chatbot' ) ); ?></h2>
				<?php echo $conversations_chart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from escaped parts in Charts. ?>
			</div>

			<div class="sai-grid-2">
				<div class="sai-card" id="sai-gaps">
					<h2><?php esc_html_e( 'Knowledge gaps', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Questions the assistant could not answer from your content. Add a Knowledge Article for each and it will answer next time.', 'all-in-one-ai-chatbot' ); ?></p>
					<?php if ( array() === $gaps ) : ?>
						<p class="sai-empty"><?php esc_html_e( 'None. The assistant answered everything it was asked. 🎉', 'all-in-one-ai-chatbot' ); ?></p>
					<?php else : ?>
						<ol class="sai-rank">
							<?php foreach ( $gaps as $gap ) : ?>
								<li>
									<span class="sai-rank-text"><?php echo esc_html( $gap['example'] ); ?></span>
									<span class="sai-count">×<?php echo esc_html( number_format_i18n( $gap['count'] ) ); ?></span>
									<span class="sai-rank-actions">
										<a class="button button-small button-primary" href="<?php echo esc_url( Report::add_answer_url( $gap['example'] ) ); ?>"><?php esc_html_e( 'Add the answer', 'all-in-one-ai-chatbot' ); ?></a>
										<a href="<?php echo esc_url( Menu::url( 'conversations', array( 'conversation' => $gap['conversation'] ) ) ); ?>"><?php esc_html_e( 'View chat', 'all-in-one-ai-chatbot' ); ?></a>
										<?php
										self::button(
											self::DISMISS,
											__( 'Dismiss', 'all-in-one-ai-chatbot' ),
											array(
												'signature' => $gap['signature'],
												'days' => $days,
											),
											'button-link'
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'What visitors ask most', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Similar questions are grouped together. Good candidates for Quick Replies.', 'all-in-one-ai-chatbot' ); ?></p>
					<?php if ( array() === $questions ) : ?>
						<p class="sai-empty"><?php esc_html_e( 'No questions yet in this period.', 'all-in-one-ai-chatbot' ); ?></p>
					<?php else : ?>
						<ol class="sai-rank">
							<?php foreach ( $questions as $question ) : ?>
								<li>
									<a class="sai-rank-text" href="<?php echo esc_url( Menu::url( 'conversations', array( 'conversation' => $question['conversation'] ) ) ); ?>"><?php echo esc_html( $question['example'] ); ?></a>
									<span class="sai-count">×<?php echo esc_html( number_format_i18n( $question['count'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>
			</div>

			<div class="sai-grid-2">
				<div class="sai-card">
					<h2><?php esc_html_e( 'When visitors chat', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Darker = busier. Useful for business hours and live-chat shifts.', 'all-in-one-ai-chatbot' ); ?></p>
					<?php echo Charts::heatmap( $stats->heatmap( $detail ), $day_names, (int) get_option( 'start_of_week', 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from escaped parts. ?>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Answers rated 👎', 'all-in-one-ai-chatbot' ); ?></h2>
					<?php if ( array() === $low ) : ?>
						<p class="sai-empty"><?php echo esc_html( Settings::get( 'feedback', false ) ? __( 'No answers were rated unhelpful.', 'all-in-one-ai-chatbot' ) : __( 'Switch on answer feedback (Settings → Widget) to see which answers visitors did not find helpful.', 'all-in-one-ai-chatbot' ) ); ?></p>
					<?php else : ?>
						<ul class="sai-low">
							<?php foreach ( $low as $item ) : ?>
								<li>
									<strong><?php echo esc_html( mb_substr( $item['question'], 0, 140 ) ); ?></strong>
									<span><?php echo esc_html( mb_substr( $item['answer'], 0, 180 ) ); ?>…</span>
									<a href="<?php echo esc_url( Menu::url( 'conversations', array( 'conversation' => $item['conversation'] ) ) ); ?>"><?php esc_html_e( 'View chat', 'all-in-one-ai-chatbot' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>

			<div class="sai-grid-2">
				<div class="sai-card">
					<h2><?php esc_html_e( 'Pages where chats start', 'all-in-one-ai-chatbot' ); ?></h2>
					<?php self::bars_list( array_map( static fn( array $p ): array => array( preg_replace( '#^https?://[^/]+#', '', $p['page'] ) ?: '/', $p['page'], $p['count'] ), $pages ) ); ?>
				</div>
				<div class="sai-card">
					<h2><?php esc_html_e( 'Most used knowledge', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Pages the assistant linked to under its answers.', 'all-in-one-ai-chatbot' ); ?></p>
					<?php self::bars_list( array_map( static fn( array $s ): array => array( $s['title'], $s['url'], $s['count'] ), $sources ) ); ?>
				</div>
			</div>

			<div class="sai-card">
				<h2><?php esc_html_e( 'AI cost per day (estimated)', 'all-in-one-ai-chatbot' ); ?></h2>
				<?php echo $cost_chart; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from escaped parts in Charts. ?>
			</div>

			<p class="description">
				<?php
				/* translators: %d: number of days */
				echo esc_html( sprintf( __( 'Questions, gaps and pages cover the conversations you keep (%d days, set under Settings → Limits & Privacy). Daily totals are kept longer, without any personal data.', 'all-in-one-ai-chatbot' ), (int) Settings::get( 'retention_days', 90 ) ) );
				?>
				<a href="<?php echo esc_url( Menu::url( 'settings', array( 'tab' => 'notify' ) ) ); ?>"><?php esc_html_e( 'Get this by email every week', 'all-in-one-ai-chatbot' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Ranked list with proportional bars.
	 *
	 * @param array<int, array{0: string, 1: string, 2: int}> $rows Label, link, count.
	 */
	private static function bars_list( array $rows ): void {
		if ( array() === $rows ) {
			echo '<p class="sai-empty">' . esc_html__( 'Nothing yet in this period.', 'all-in-one-ai-chatbot' ) . '</p>';
			return;
		}

		$max = max( 1, max( array_column( $rows, 2 ) ) );

		echo '<ul class="sai-bars">';

		foreach ( $rows as [ $label, $url, $count ] ) {
			printf(
				'<li><span class="sai-bars-fill" style="width:%1$d%%"></span><a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a><span class="sai-count">%4$s</span></li>',
				(int) round( 100 * $count / $max ),
				esc_url( $url ),
				esc_html( mb_substr( $label, 0, 80 ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Sum daily metrics into weeks (keyed by the week's first day).
	 *
	 * @param array<string, array<string, float|int>> $series Daily.
	 * @return array<string, array<string, float|int>>
	 */
	private static function weekly( array $series ): array {
		$weeks = array();
		$i     = 0;
		$key   = '';

		foreach ( $series as $date => $metrics ) {
			if ( 0 === $i % 7 ) {
				$key           = $date;
				$weeks[ $key ] = Stats::zero();
			}

			foreach ( Stats::METRICS as $metric ) {
				$weeks[ $key ][ $metric ] += $metrics[ $metric ];
			}

			++$i;
		}

		return $weeks;
	}

	/**
	 * "2 min 5 s".
	 *
	 * @param int $seconds Seconds.
	 */
	private static function duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%d s', 'all-in-one-ai-chatbot' ), $seconds );
		}

		/* translators: 1: minutes, 2: seconds */
		return sprintf( __( '%1$d min %2$d s', 'all-in-one-ai-chatbot' ), intdiv( $seconds, 60 ), $seconds % 60 );
	}
}

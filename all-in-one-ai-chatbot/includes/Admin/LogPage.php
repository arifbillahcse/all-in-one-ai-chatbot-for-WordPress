<?php
/**
 * Activity log screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Shows background activity (emails, webhooks, syncs) and the queue's health.
 */
final class LogPage {

	private const RETRY_ACTION = 'softorio_ai_retry_jobs';

	/**
	 * Hook the "Retry failed" form handler.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::RETRY_ACTION, array( self::class, 'retry' ) );
	}

	/**
	 * Requeue failed jobs.
	 */
	public static function retry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::RETRY_ACTION );

		$count = Queue::retry_failed();
		Queue::run( 10 );

		wp_safe_redirect( Menu::url( 'log', array( 'retried' => $count ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$level   = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
		$retried = isset( $_GET['retried'] ) ? absint( $_GET['retried'] ) : null;
		// phpcs:enable

		$level  = in_array( $level, array( Log::INFO, Log::WARNING, Log::ERROR ), true ) ? $level : '';
		$rows   = Log::recent( 200, $level, $channel );
		$counts = Queue::counts();
		?>
		<div class="wrap sai-admin">
			<h1><?php esc_html_e( 'Activity Log', 'all-in-one-ai-chatbot' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Background work such as emails, notifications and integrations. Check here if something did not arrive.', 'all-in-one-ai-chatbot' ); ?></p>

			<?php if ( null !== $retried ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of jobs */
					echo esc_html( sprintf( _n( '%d job queued again.', '%d jobs queued again.', $retried, 'all-in-one-ai-chatbot' ), $retried ) );
					?>
				</p></div>
			<?php endif; ?>

			<div class="sai-card sai-queue">
				<strong><?php esc_html_e( 'Queue:', 'all-in-one-ai-chatbot' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: waiting jobs, 2: failed jobs */
						__( '%1$d waiting, %2$d failed', 'all-in-one-ai-chatbot' ),
						$counts['pending'],
						$counts['failed']
					)
				);
				?>
				<?php if ( $counts['failed'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sai-inline-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::RETRY_ACTION ); ?>">
						<?php wp_nonce_field( self::RETRY_ACTION ); ?>
						<button class="button"><?php esc_html_e( 'Retry failed', 'all-in-one-ai-chatbot' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<form method="get" class="sai-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG . '-log' ); ?>">
				<select name="level">
					<option value=""><?php esc_html_e( 'All levels', 'all-in-one-ai-chatbot' ); ?></option>
					<?php foreach ( array( Log::ERROR, Log::WARNING, Log::INFO ) as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( ucfirst( $option ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="channel">
					<option value=""><?php esc_html_e( 'All areas', 'all-in-one-ai-chatbot' ); ?></option>
					<?php foreach ( Log::channels() as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $channel, $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'all-in-one-ai-chatbot' ); ?></button>
			</form>

			<table class="widefat striped sai-table sai-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Level', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Area', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'What happened', 'all-in-one-ai-chatbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $rows ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Nothing logged yet.', 'all-in-one-ai-chatbot' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="sai-nowrap"><?php echo esc_html( self::when( (string) $row['created_at'] ) ); ?></td>
							<td><span class="sai-level sai-level-<?php echo esc_attr( (string) $row['level'] ); ?>"><?php echo esc_html( ucfirst( (string) $row['level'] ) ); ?></span></td>
							<td><?php echo esc_html( (string) $row['channel'] ); ?></td>
							<td>
								<?php echo esc_html( (string) $row['message'] ); ?>
								<?php if ( ! empty( $row['context'] ) ) : ?>
									<details><summary><?php esc_html_e( 'Details', 'all-in-one-ai-chatbot' ); ?></summary><pre><?php echo esc_html( (string) wp_json_encode( json_decode( (string) $row['context'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A stored UTC datetime in the site's timezone.
	 *
	 * @param string $gmt MySQL datetime, UTC.
	 */
	private static function when( string $gmt ): string {
		$time = strtotime( $gmt . ' UTC' );

		return false === $time ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}

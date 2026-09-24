<?php
/**
 * Leads screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Leads\LeadStore;

defined( 'ABSPATH' ) || exit;

/**
 * Lists leads, changes their status, deletes them and exports CSV.
 */
final class LeadsPage {

	private const PER_PAGE      = 25;
	private const EXPORT_ACTION = 'softorio_ai_export_leads';
	private const UPDATE_ACTION = 'softorio_ai_update_lead';

	/**
	 * Hook the form handlers.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'export' ) );
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( self::class, 'update' ) );
	}

	/**
	 * Labels for statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'new'       => __( 'New', 'all-in-one-ai-chatbot' ),
			'contacted' => __( 'Contacted', 'all-in-one-ai-chatbot' ),
			'won'       => __( 'Won', 'all-in-one-ai-chatbot' ),
			'closed'    => __( 'Closed', 'all-in-one-ai-chatbot' ),
		);
	}

	/**
	 * Labels for where a lead came from.
	 *
	 * @param string $source Source key.
	 */
	public static function source_label( string $source ): string {
		return match ( $source ) {
			'pre_chat' => __( 'Before chat', 'all-in-one-ai-chatbot' ),
			'handoff'  => __( 'Asked for a person', 'all-in-one-ai-chatbot' ),
			'fallback' => __( 'Bot could not answer', 'all-in-one-ai-chatbot' ),
			default    => $source,
		};
	}

	/**
	 * Change status or delete.
	 */
	public static function update(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::UPDATE_ACTION );

		$id    = isset( $_POST['lead'] ) ? absint( $_POST['lead'] ) : 0;
		$store = new LeadStore();

		if ( isset( $_POST['delete'] ) ) {
			$store->delete( $id );
		} elseif ( isset( $_POST['status'] ) ) {
			$store->set_status( $id, sanitize_key( wp_unslash( $_POST['status'] ) ) );
		}

		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : Menu::url( 'leads' );

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Stream every lead as CSV.
	 */
	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::EXPORT_ACTION );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leads-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		// UTF-8 byte order mark, so Excel shows Bangla and other scripts correctly.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming a download.
		fputcsv( $out, array( 'id', 'created_at_utc', 'name', 'email', 'phone', 'message', 'source', 'status', 'consent', 'consent_text', 'page_url' ) );

		foreach ( ( new LeadStore() )->each() as $row ) {
			fputcsv(
				$out,
				array_map(
					array( self::class, 'csv_safe' ),
					array( $row['id'], $row['created_at'], $row['name'], $row['email'], $row['phone'], $row['message'], $row['source'], $row['status'], $row['consent'] ? 'yes' : 'no', $row['consent_text'], $row['page_url'] )
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download.
		exit;
	}

	/**
	 * Stop spreadsheet formula injection: visitor-typed text starting with
	 * = + - @ would run as a formula when the owner opens the file.
	 *
	 * @param mixed $value Cell value.
	 */
	public static function csv_safe( mixed $value ): string {
		$value = (string) $value;

		return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Render the list.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$result = ( new LeadStore() )->search( $search, $status, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$pages  = (int) ceil( $result['total'] / self::PER_PAGE );
		$here   = Menu::url( 'leads', array_filter( array( 's' => $search, 'status' => $status, 'paged' => $paged > 1 ? $paged : null ) ) );
		?>
		<div class="wrap sai-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'all-in-one-ai-chatbot' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sai-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>">
				<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
				<button class="page-title-action"><?php esc_html_e( 'Export CSV', 'all-in-one-ai-chatbot' ); ?></button>
			</form>

			<?php if ( 'off' === \Softorio\AiAssistant\Settings::get( 'leads_mode', 'off' ) ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Lead capture is switched off.', 'all-in-one-ai-chatbot' ); ?>
					<a href="<?php echo esc_url( Menu::url( 'settings', array( 'tab' => 'leads' ) ) ); ?>"><?php esc_html_e( 'Turn it on', 'all-in-one-ai-chatbot' ); ?></a>
				</p></div>
			<?php endif; ?>

			<form method="get" class="sai-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG . '-leads' ); ?>">
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'all-in-one-ai-chatbot' ); ?></option>
					<?php foreach ( self::statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email, phone…', 'all-in-one-ai-chatbot' ); ?>">
				<button class="button"><?php esc_html_e( 'Filter', 'all-in-one-ai-chatbot' ); ?></button>
			</form>

			<table class="widefat striped sai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Contact', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Message', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Source', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Received', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Status', 'all-in-one-ai-chatbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $result['rows'] ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No leads yet.', 'all-in-one-ai-chatbot' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( '' !== $row['name'] ? $row['name'] : '—' ); ?></strong><br>
								<?php if ( '' !== $row['email'] ) : ?>
									<a href="<?php echo esc_url( 'mailto:' . $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a><br>
								<?php endif; ?>
								<?php if ( '' !== $row['phone'] ) : ?>
									<?php $wa = preg_replace( '/\D+/', '', (string) $row['phone'] ); ?>
									<?php echo esc_html( $row['phone'] ); ?> · <a href="<?php echo esc_url( 'https://wa.me/' . $wa ); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
								<?php endif; ?>
							</td>
							<td class="sai-lead-message">
								<?php echo esc_html( mb_substr( (string) $row['message'], 0, 200 ) ); ?>
								<?php if ( (int) $row['conversation_id'] > 0 ) : ?>
									<br><a href="<?php echo esc_url( Menu::url( 'conversations', array( 'conversation' => (int) $row['conversation_id'] ) ) ); ?>"><?php esc_html_e( 'View chat', 'all-in-one-ai-chatbot' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::source_label( (string) $row['source'] ) ); ?><?php echo $row['consent'] ? ' <span class="sai-level" title="' . esc_attr( (string) $row['consent_text'] ) . '">' . esc_html__( 'consent', 'all-in-one-ai-chatbot' ) . '</span>' : ''; ?></td>
							<td class="sai-nowrap"><?php echo esc_html( self::when( (string) $row['created_at'] ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sai-lead-actions">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::UPDATE_ACTION ); ?>">
									<input type="hidden" name="lead" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<input type="hidden" name="back" value="<?php echo esc_url( $here ); ?>">
									<?php wp_nonce_field( self::UPDATE_ACTION ); ?>
									<select name="status" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Status', 'all-in-one-ai-chatbot' ); ?>">
										<?php foreach ( self::statuses() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<button name="delete" value="1" class="button-link button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this lead permanently?', 'all-in-one-ai-chatbot' ) ); ?>')"><?php esc_html_e( 'Delete', 'all-in-one-ai-chatbot' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
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

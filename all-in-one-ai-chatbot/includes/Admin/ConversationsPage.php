<?php
/**
 * Conversations screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Lists conversations and shows a transcript.
 *
 * Reading real questions is the fastest way for a site owner to find what
 * their content is missing, so each transcript links straight to "add a
 * Knowledge Article".
 */
final class ConversationsPage {

	private const PER_PAGE = 25;

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$id = isset( $_GET['conversation'] ) ? absint( $_GET['conversation'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.

		if ( $id > 0 ) {
			self::render_one( $id );

			return;
		}

		self::render_list();
	}

	/**
	 * Paged list with search.
	 */
	private static function render_list(): void {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$t      = Installer::tables();
		$where  = '1=1';
		$params = array( $t['conversations'] );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where    = 'id IN (SELECT conversation_id FROM %i WHERE content LIKE %s)';
			$params[] = $t['messages'];
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; $where is built from fixed strings.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE $where ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$pages = (int) ceil( $total / self::PER_PAGE );
		?>
		<div class="wrap sai-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Conversations', 'all-in-one-ai-chatbot' ); ?></h1>

			<form method="get" class="sai-list-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG . '-conversations' ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="sai-conv-search"><?php esc_html_e( 'Search conversations', 'all-in-one-ai-chatbot' ); ?></label>
					<input type="search" id="sai-conv-search" name="s" value="<?php echo esc_attr( $search ); ?>">
					<button class="button"><?php esc_html_e( 'Search messages', 'all-in-one-ai-chatbot' ); ?></button>
				</p>
			</form>

			<table class="widefat striped sai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'First question', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Messages', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Started on', 'all-in-one-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Last activity', 'all-in-one-ai-chatbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php echo '' !== $search ? esc_html__( 'No conversations match that search.', 'all-in-one-ai-chatbot' ) : esc_html__( 'No conversations yet. They appear here as soon as visitors start chatting.', 'all-in-one-ai-chatbot' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( Menu::url( 'conversations', array( 'conversation' => (int) $row['id'] ) ) ); ?>">
										<?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no messages)', 'all-in-one-ai-chatbot' ) ); ?>
									</a>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['message_count'] ) ); ?></td>
								<td><?php echo esc_html( '$' . number_format_i18n( (float) $row['total_cost'], 4 ) ); ?></td>
								<td class="sai-url"><?php echo esc_html( self::short_url( (string) $row['page_url'] ) ); ?></td>
								<td><?php echo esc_html( self::when( (string) $row['updated_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
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
	 * One transcript.
	 *
	 * @param int $id Conversation id.
	 */
	private static function render_one( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::tables()['conversations'], $id ), ARRAY_A );

		$back = Menu::url( 'conversations' );
		?>
		<div class="wrap sai-admin">
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'All conversations', 'all-in-one-ai-chatbot' ); ?></a></p>

			<?php if ( ! is_array( $row ) ) : ?>
				<p><?php esc_html_e( 'That conversation no longer exists.', 'all-in-one-ai-chatbot' ); ?></p>
				</div>
				<?php
				return;
			endif;

			$messages = ( new ConversationStore() )->transcript( $id, 500 );
			?>
			<h1><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( 'Conversation', 'all-in-one-ai-chatbot' ) ); ?></h1>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: start time, 2: page URL, 3: cost */
						__( 'Started %1$s on %2$s · estimated cost $%3$s', 'all-in-one-ai-chatbot' ),
						self::when( (string) $row['created_at'] ),
						'' !== $row['page_url'] ? $row['page_url'] : '—',
						number_format_i18n( (float) $row['total_cost'], 4 )
					)
				);
				?>
			</p>

			<div class="sai-transcript">
				<?php foreach ( $messages as $message ) : ?>
					<div class="sai-turn sai-turn-<?php echo 'user' === $message['role'] ? 'user' : 'bot'; ?>">
						<div class="sai-turn-meta">
							<?php
							echo esc_html( 'user' === $message['role'] ? __( 'Visitor', 'all-in-one-ai-chatbot' ) : __( 'Assistant', 'all-in-one-ai-chatbot' ) );
							echo ' · ' . esc_html( self::when( $message['created_at'] ) );

							if ( 'assistant' === $message['role'] && '' !== $message['model'] ) {
								echo ' · ' . esc_html( $message['model'] );
							}
							?>
						</div>
						<div class="sai-turn-text"><?php echo nl2br( esc_html( $message['content'] ) ); ?></div>
						<?php if ( ! empty( $message['sources'] ) ) : ?>
							<div class="sai-turn-sources">
								<?php foreach ( $message['sources'] as $source ) : ?>
									<a href="<?php echo esc_url( (string) ( $source['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) ( $source['title'] ?? '' ) ); ?></a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="sai-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=softorio_ai_doc' ) ); ?>"><?php esc_html_e( 'Add a Knowledge Article to improve answers', 'all-in-one-ai-chatbot' ); ?></a>
				<button type="button" class="button button-link-delete" id="sai-delete-conversation" data-id="<?php echo esc_attr( (string) $id ); ?>" data-back="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Delete conversation', 'all-in-one-ai-chatbot' ); ?></button>
			</p>
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

	/**
	 * The path part of a URL, for compact display.
	 *
	 * @param string $url URL.
	 */
	private static function short_url( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '—';
	}
}

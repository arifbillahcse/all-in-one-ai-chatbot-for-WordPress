<?php
/**
 * Overview screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Chat\ChatService;
use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Knowledge\Embedder;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\Knowledge\IndexStore;
use Softorio\AiAssistant\PostTypes;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Setup checklist, knowledge index, test search and usage.
 */
final class DashboardPage {

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats      = ( new IndexStore() )->stats();
		$state      = get_option( Indexer::STATE_OPTION, array() );
		$today      = ChatService::usage_today();
		$month      = ( new ConversationStore() )->usage_since( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
		$ratings    = ( new ConversationStore() )->ratings_since( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
		$rated      = $ratings['up'] + $ratings['down'];
		$last_error = get_option( 'softorio_ai_last_error', array() );
		$docs       = wp_count_posts( PostTypes::DOC );
		$doc_count  = isset( $docs->publish ) ? (int) $docs->publish : 0;
		$ready      = Settings::is_ready();

		$checks = array(
			array(
				'ok'   => $ready,
				'text' => __( 'An AI provider API key is saved', 'all-in-one-ai-chatbot' ),
				'link' => Menu::url( 'settings' ),
			),
			array(
				'ok'   => $stats['chunks'] > 0,
				'text' => __( 'Your content is indexed', 'all-in-one-ai-chatbot' ),
				'link' => '#sai-index',
			),
			array(
				'ok'   => $doc_count > 0,
				'text' => __( 'At least one Knowledge Article (FAQs, policies, contact details)', 'all-in-one-ai-chatbot' ),
				'link' => admin_url( 'post-new.php?post_type=' . PostTypes::DOC ),
			),
			array(
				'ok'   => '' !== (string) Settings::get( 'whatsapp', '' ) || '' !== (string) Settings::get( 'contact_email', '' ) || '' !== (string) Settings::get( 'contact_url', '' ),
				'text' => __( 'A way to reach a person (WhatsApp, email or contact page)', 'all-in-one-ai-chatbot' ),
				'link' => Menu::url( 'settings' ),
			),
			array(
				'ok'   => (bool) Settings::get( 'enabled', true ),
				'text' => __( 'The widget is switched on', 'all-in-one-ai-chatbot' ),
				'link' => Menu::url( 'settings' ),
			),
		);
		?>
		<div class="wrap sai-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Chatbot', 'all-in-one-ai-chatbot' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( Menu::url( 'analytics' ) ); ?>"><?php esc_html_e( 'Full analytics', 'all-in-one-ai-chatbot' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( ! empty( $last_error['time'] ) && time() - (int) $last_error['time'] < DAY_IN_SECONDS ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Recent AI provider error:', 'all-in-one-ai-chatbot' ); ?></strong>
						<?php echo esc_html( sprintf( '%s — %s (%s)', (string) $last_error['provider'], (string) $last_error['message'], human_time_diff( (int) $last_error['time'] ) ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php $queue = \Softorio\AiAssistant\Support\Queue::counts(); ?>
			<?php if ( $queue['failed'] > 0 ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of failed jobs */
								_n( '%d background task (email, notification or integration) failed.', '%d background tasks (emails, notifications or integrations) failed.', $queue['failed'], 'all-in-one-ai-chatbot' ),
								$queue['failed']
							)
						);
						?>
						<a href="<?php echo esc_url( Menu::url( 'log', array( 'level' => 'error' ) ) ); ?>"><?php esc_html_e( 'See the activity log', 'all-in-one-ai-chatbot' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="sai-grid">
				<div class="sai-card">
					<h2><?php esc_html_e( 'Setup', 'all-in-one-ai-chatbot' ); ?></h2>
					<ul class="sai-checklist">
						<?php foreach ( $checks as $check ) : ?>
							<li class="<?php echo $check['ok'] ? 'is-ok' : 'is-todo'; ?>">
								<span class="dashicons <?php echo $check['ok'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
								<?php if ( $check['ok'] ) : ?>
									<?php echo esc_html( $check['text'] ); ?>
								<?php else : ?>
									<a href="<?php echo esc_url( $check['link'] ); ?>"><?php echo esc_html( $check['text'] ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Usage', 'all-in-one-ai-chatbot' ); ?></h2>
					<div class="sai-stats">
						<div><strong><?php echo esc_html( number_format_i18n( $today['answers'] ) ); ?></strong><span><?php esc_html_e( 'answers today', 'all-in-one-ai-chatbot' ); ?></span></div>
						<div><strong><?php echo esc_html( '$' . number_format_i18n( $today['cost'], 4 ) ); ?></strong><span><?php esc_html_e( 'estimated cost today', 'all-in-one-ai-chatbot' ); ?></span></div>
						<div><strong><?php echo esc_html( number_format_i18n( $month['answers'] ) ); ?></strong><span><?php esc_html_e( 'answers, last 30 days', 'all-in-one-ai-chatbot' ); ?></span></div>
						<div><strong><?php echo esc_html( '$' . number_format_i18n( $month['cost'], $month['cost'] < 1 ? 4 : 2 ) ); ?></strong><span><?php esc_html_e( 'estimated, last 30 days', 'all-in-one-ai-chatbot' ); ?></span></div>
						<?php if ( $rated > 0 ) : ?>
							<div>
								<strong><?php echo esc_html( number_format_i18n( 100 * $ratings['up'] / $rated ) . '%' ); ?></strong>
								<span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: helpful ratings, 2: not helpful ratings */
											__( 'helpful (👍 %1$d · 👎 %2$d), last 30 days', 'all-in-one-ai-chatbot' ),
											$ratings['up'],
											$ratings['down']
										)
									);
									?>
								</span>
							</div>
						<?php endif; ?>
					</div>
					<p class="description">
						<?php
						$cap    = (int) Settings::get( 'daily_message_cap', 0 );
						$budget = (float) Settings::get( 'daily_budget', 0 );
						echo esc_html(
							sprintf(
								/* translators: 1: daily answer cap, 2: daily budget in USD */
								__( 'Daily limits: %1$s answers, %2$s budget.', 'all-in-one-ai-chatbot' ),
								$cap > 0 ? number_format_i18n( $cap ) : __( 'unlimited', 'all-in-one-ai-chatbot' ),
								$budget > 0 ? '$' . number_format_i18n( $budget, 2 ) : __( 'unlimited', 'all-in-one-ai-chatbot' )
							)
						);
						?>
						<a href="<?php echo esc_url( Menu::url( 'conversations' ) ); ?>"><?php esc_html_e( 'View conversations', 'all-in-one-ai-chatbot' ); ?></a>
					</p>
				</div>
			</div>

			<div class="sai-card" id="sai-index">
				<h2><?php esc_html_e( 'Knowledge index', 'all-in-one-ai-chatbot' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of pages/posts, 2: number of passages */
							__( '%1$s items indexed, split into %2$s searchable passages.', 'all-in-one-ai-chatbot' ),
							number_format_i18n( $stats['posts'] ),
							number_format_i18n( $stats['chunks'] )
						)
					);

					if ( Embedder::enabled() ) {
						echo ' ' . esc_html(
							sprintf(
								/* translators: %s: number of passages with embeddings */
								__( 'Semantic search: %s passages ready.', 'all-in-one-ai-chatbot' ),
								number_format_i18n( $stats['embedded'] )
							)
						);
					}
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Content is re-indexed automatically whenever you publish or update it. Rebuild only after changing which content types the assistant reads, or if something looks out of date.', 'all-in-one-ai-chatbot' ); ?>
					<?php if ( in_array( $state['status'] ?? '', array( 'pending', 'running' ), true ) ) : ?>
						<strong><?php esc_html_e( 'A background build is in progress.', 'all-in-one-ai-chatbot' ); ?></strong>
					<?php endif; ?>
				</p>
				<p>
					<button type="button" class="button button-primary" id="sai-rebuild"><?php esc_html_e( 'Rebuild index now', 'all-in-one-ai-chatbot' ); ?></button>
					<span id="sai-rebuild-status" role="status"></span>
				</p>
				<progress id="sai-rebuild-progress" max="100" value="0" hidden></progress>
			</div>

			<div class="sai-card">
				<h2><?php esc_html_e( 'Test what the assistant finds', 'all-in-one-ai-chatbot' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Type a question a visitor might ask to see which of your content would be used to answer it. This does not call the AI and costs nothing.', 'all-in-one-ai-chatbot' ); ?></p>
				<form id="sai-search-form" class="sai-search">
					<input type="search" id="sai-search-q" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. How long does delivery take?', 'all-in-one-ai-chatbot' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'all-in-one-ai-chatbot' ); ?></button>
				</form>
				<ol id="sai-search-results" class="sai-results"></ol>
			</div>
		</div>
		<?php
	}
}

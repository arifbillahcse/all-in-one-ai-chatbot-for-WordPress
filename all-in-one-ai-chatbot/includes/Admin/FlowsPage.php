<?php
/**
 * Quick Replies builder screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Flows\FlowStore;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Edit the button menu tree. The builder is JavaScript; the tree travels
 * as JSON in one form field and is fully re-validated on save.
 */
final class FlowsPage {

	private const ACTION = 'softorio_ai_save_flows';

	/**
	 * Hook the save handler.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	/**
	 * Save the tree and the on/off switch.
	 */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$json = isset( $_POST['flows'] ) ? wp_unslash( $_POST['flows'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, sanitised node by node in FlowStore.
		$tree = json_decode( (string) $json, true );

		FlowStore::save( is_array( $tree ) ? $tree : array() );

		$settings                  = Settings::all();
		$settings['flows_enabled'] = ! empty( $_POST['flows_enabled'] );
		update_option( Settings::OPTION, $settings );

		wp_safe_redirect( Menu::url( 'flows', array( 'saved' => 1 ) ) );
		exit;
	}

	/**
	 * Render the builder.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$saved = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		?>
		<div class="wrap sai-admin">
			<h1><?php esc_html_e( 'Quick Replies', 'all-in-one-ai-chatbot' ); ?></h1>
			<p class="description" style="max-width:760px">
				<?php esc_html_e( 'Buttons shown when the chat opens. They answer common questions instantly, without using the AI (so they cost nothing). A button can show a reply and a sub-menu, open a link, open the lead form, show how to reach a person, or pass a question to the AI.', 'all-in-one-ai-chatbot' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Quick replies saved.', 'all-in-one-ai-chatbot' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sai-flows-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="flows" id="sai-flows-json" value="<?php echo esc_attr( (string) wp_json_encode( FlowStore::get() ) ); ?>">

				<div class="sai-card">
					<label>
						<input type="checkbox" name="flows_enabled" value="1" <?php checked( (bool) Settings::get( 'flows_enabled', false ) ); ?>>
						<?php esc_html_e( 'Show these buttons in the chat (they replace "Suggested questions")', 'all-in-one-ai-chatbot' ); ?>
					</label>
				</div>

				<div class="sai-card">
					<div id="sai-flows" class="sai-flows"></div>
					<p>
						<button type="button" class="button" id="sai-flow-add"><?php esc_html_e( '+ Add button', 'all-in-one-ai-chatbot' ); ?></button>
						<button type="button" class="button-link" id="sai-flow-example" data-example="<?php echo esc_attr( (string) wp_json_encode( FlowStore::example() ) ); ?>"><?php esc_html_e( 'Load an example', 'all-in-one-ai-chatbot' ); ?></button>
					</p>
				</div>

				<?php submit_button( __( 'Save quick replies', 'all-in-one-ai-chatbot' ) ); ?>
			</form>
		</div>
		<?php
	}
}

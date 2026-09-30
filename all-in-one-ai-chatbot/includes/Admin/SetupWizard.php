<?php
/**
 * First-run setup wizard.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Knowledge\IndexStore;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Five short steps from activation to a working chatbot: connect an AI,
 * name it, choose its knowledge, say how visitors reach the team, done.
 *
 * Each step is a normal settings form limited to its own fields (the
 * "_fields" list), posted to options.php, so saving, validation and
 * encryption of keys are exactly those of the Settings screen. The next
 * step is where options.php sends the owner back to.
 */
final class SetupWizard {

	public const SLUG     = Menu::SLUG . '-setup';
	public const REDIRECT = 'softorio_ai_do_setup';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ) );
	}

	/**
	 * After activation, open the wizard once (not for bulk or network
	 * activation, and not when the plugin is already set up).
	 */
	public static function maybe_redirect(): void {
		if ( ! get_option( self::REDIRECT ) ) {
			return;
		}

		delete_option( self::REDIRECT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of WordPress's own flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) || Settings::is_ready() ) {
			return;
		}

		wp_safe_redirect( self::url( 1 ) );
		exit;
	}

	/**
	 * URL of a step.
	 *
	 * @param int                  $step Step (1–5).
	 * @param array<string, mixed> $args More query args.
	 */
	public static function url( int $step, array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'step' => $step,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The steps: title, intro and fields.
	 *
	 * @return array<int, array{title: string, intro: string, fields: array<int, string>}>
	 */
	public static function steps(): array {
		return array(
			1 => array(
				'title'  => __( 'Connect an AI', 'all-in-one-ai-chatbot' ),
				'intro'  => __( 'The assistant uses your own account with an AI provider; you pay the provider directly for what it uses (usually well under one US cent per answer). Pick one and paste its API key. Keys are stored encrypted.', 'all-in-one-ai-chatbot' ),
				'fields' => array_merge( array( 'provider' ), array_map( static fn( string $id ): string => $id . '_key', array_keys( Settings::providers() ) ) ),
			),
			2 => array(
				'title'  => __( 'Your assistant', 'all-in-one-ai-chatbot' ),
				'intro'  => __( 'How the chat introduces itself and looks on your site. You can change all of this later.', 'all-in-one-ai-chatbot' ),
				'fields' => array( 'assistant_name', 'company_name', 'greeting', 'color', 'position' ),
			),
			3 => array(
				'title'  => __( 'What it knows', 'all-in-one-ai-chatbot' ),
				'intro'  => __( 'The assistant answers only from your content. Choose what it reads, then build the index. Later you can add documents, FAQ spreadsheets and other websites under Knowledge Sources.', 'all-in-one-ai-chatbot' ),
				'fields' => array( 'post_types' ),
			),
			4 => array(
				'title'  => __( 'Reaching your team', 'all-in-one-ai-chatbot' ),
				'intro'  => __( 'When the assistant cannot help, it offers these. Leads (visitors\' contact details) appear under AI Chatbot → Leads and are emailed to you.', 'all-in-one-ai-chatbot' ),
				'fields' => array( 'whatsapp', 'contact_email', 'leads_mode', 'notify_email' ),
			),
			5 => array(
				'title'  => __( 'All set', 'all-in-one-ai-chatbot' ),
				'intro'  => '',
				'fields' => array(),
			),
		);
	}

	/**
	 * Render the current step.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$steps = self::steps();
		$step  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$step  = isset( $steps[ $step ] ) ? $step : 1;
		$data  = $steps[ $step ];
		$saved = isset( $_GET['settings-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		?>
		<div class="wrap sai-admin sai-wizard">
			<h1><?php esc_html_e( 'Set up your AI chatbot', 'all-in-one-ai-chatbot' ); ?></h1>

			<ol class="sai-wizard-steps">
				<?php foreach ( $steps as $number => $info ) : ?>
					<li class="<?php echo esc_attr( $number < $step ? 'is-done' : ( $number === $step ? 'is-current' : '' ) ); ?>" <?php echo $number === $step ? 'aria-current="step"' : ''; ?>>
						<?php if ( $number < $step ) : ?>
							<a href="<?php echo esc_url( self::url( $number ) ); ?>"><?php echo esc_html( $info['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $info['title'] ); ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="sai-card sai-wizard-card">
				<h2><?php echo esc_html( $step . '. ' . $data['title'] ); ?></h2>

				<?php
				// Coming from step 1: prove the key works before going on.
				if ( 2 === $step && $saved ) {
					self::provider_notice();
				}
				?>

				<?php if ( '' !== $data['intro'] ) : ?>
					<p class="sai-wizard-intro"><?php echo esc_html( $data['intro'] ); ?></p>
				<?php endif; ?>

				<?php if ( 5 === $step ) : ?>
					<?php self::done(); ?>
				<?php else : ?>
					<?php self::form( $step, $data['fields'] ); ?>
				<?php endif; ?>
			</div>

			<p class="sai-wizard-skip">
				<a href="<?php echo esc_url( Menu::url() ); ?>"><?php esc_html_e( 'Skip the wizard and use the full settings', 'all-in-one-ai-chatbot' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * A step's form, posted to options.php and returning to the next step.
	 *
	 * @param int                $step   Step.
	 * @param array<int, string> $fields Field keys.
	 */
	private static function form( int $step, array $fields ): void {
		$schema   = SettingsSchema::fields();
		$settings = Settings::all();
		$next     = self::url( $step + 1 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<input type="hidden" name="option_page" value="softorio_ai">
			<input type="hidden" name="action" value="update">
			<?php wp_nonce_field( 'softorio_ai-options', '_wpnonce', false ); ?>
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( wp_make_link_relative( $next ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( SettingsPage::name( '_tab' ) ); ?>" value="wizard">
			<input type="hidden" name="<?php echo esc_attr( SettingsPage::name( '_fields' ) ); ?>" value="<?php echo esc_attr( implode( ',', $fields ) ); ?>">

			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key ) : ?>
					<?php
					if ( ! isset( $schema[ $key ] ) ) {
						continue;
					}
					?>
					<tr<?php echo str_ends_with( $key, '_key' ) ? ' data-sai-provider-row="' . esc_attr( substr( $key, 0, -4 ) ) . '"' : ''; ?>>
						<th scope="row"><label for="<?php echo esc_attr( 'sai-' . $key ); ?>"><?php echo esc_html( (string) ( $schema[ $key ]['label'] ?? $key ) ); ?></label></th>
						<td><?php SettingsPage::field( $key, $schema[ $key ], $settings[ $key ] ?? null ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( 1 === $step ) : ?>
				<script>
				( function () {
					// Show only the key box of the chosen provider.
					var select = document.getElementById( 'sai-provider' );
					function sync() {
						document.querySelectorAll( '[data-sai-provider-row]' ).forEach( function ( row ) {
							row.hidden = row.getAttribute( 'data-sai-provider-row' ) !== select.value;
						} );
					}
					if ( select ) {
						select.addEventListener( 'change', sync );
						sync();
					}
				} )();
				</script>
			<?php endif; ?>

			<?php if ( 3 === $step ) : ?>
				<?php self::index_box(); ?>
			<?php endif; ?>

			<p class="sai-wizard-actions">
				<?php if ( $step > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( $step - 1 ) ); ?>"><?php esc_html_e( 'Back', 'all-in-one-ai-chatbot' ); ?></a>
				<?php endif; ?>
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save and continue', 'all-in-one-ai-chatbot' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Test the chosen provider right after its key was saved.
	 */
	private static function provider_notice(): void {
		$provider = (string) Settings::get( 'provider', 'openai' );

		try {
			$message = Ajax::check_provider( $provider );
			printf( '<div class="notice notice-success inline"><p>✅ %s</p></div>', esc_html( $message ) );
		} catch ( \RuntimeException $e ) {
			printf(
				'<div class="notice notice-error inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( sprintf( /* translators: %s: error message */ __( 'The AI provider did not accept the key: %s', 'all-in-one-ai-chatbot' ), $e->getMessage() ) ),
				esc_url( self::url( 1 ) ),
				esc_html__( 'Go back and fix it', 'all-in-one-ai-chatbot' )
			);
		}
	}

	/**
	 * The "Build index" button (admin.js drives it, as on the Overview).
	 */
	private static function index_box(): void {
		$stats = ( new IndexStore() )->stats();
		?>
		<div class="sai-wizard-index">
			<p>
				<?php
				/* translators: 1: number of pages/posts, 2: number of passages */
				echo esc_html( sprintf( __( 'Indexed now: %1$s pages and posts (%2$s passages).', 'all-in-one-ai-chatbot' ), number_format_i18n( $stats['posts'] ), number_format_i18n( $stats['chunks'] ) ) );
				?>
			</p>
			<p>
				<button type="button" class="button" id="sai-rebuild"><?php esc_html_e( 'Build the index now', 'all-in-one-ai-chatbot' ); ?></button>
				<span id="sai-rebuild-status" role="status"></span>
			</p>
			<progress id="sai-rebuild-progress" max="100" value="0" hidden></progress>
			<p class="description"><?php esc_html_e( 'Save first if you changed the content types. Indexing also continues in the background.', 'all-in-one-ai-chatbot' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The last step: what is ready, and what to try next.
	 */
	private static function done(): void {
		$ready = Settings::is_ready();
		$next  = array(
			array( Menu::url( 'sources' ), __( 'Add documents, FAQ spreadsheets or other websites', 'all-in-one-ai-chatbot' ), __( 'Knowledge Sources', 'all-in-one-ai-chatbot' ) ),
			array( Menu::url( 'flows' ), __( 'Answer the most common questions instantly with buttons', 'all-in-one-ai-chatbot' ), __( 'Quick Replies', 'all-in-one-ai-chatbot' ) ),
			array( Menu::url( 'settings', array( 'tab' => 'widget' ) ), __( 'Avatar, pop-up greeting, business hours, where it shows', 'all-in-one-ai-chatbot' ), __( 'Widget settings', 'all-in-one-ai-chatbot' ) ),
			array( Menu::url( 'settings', array( 'tab' => 'live' ) ), __( 'Let your team take over chats from WP Admin or Telegram', 'all-in-one-ai-chatbot' ), __( 'Live chat', 'all-in-one-ai-chatbot' ) ),
			array( Menu::url( 'settings', array( 'tab' => 'integrations' ) ), __( 'Send leads to HubSpot, Mailchimp or Brevo', 'all-in-one-ai-chatbot' ), __( 'Integrations', 'all-in-one-ai-chatbot' ) ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			array_unshift( $next, array( Menu::url( 'settings', array( 'tab' => 'woocommerce' ) ), __( 'Product search, add to cart and order tracking in the chat', 'all-in-one-ai-chatbot' ), __( 'Shop assistant', 'all-in-one-ai-chatbot' ) ) );
		}
		?>
		<?php if ( $ready ) : ?>
			<p class="sai-wizard-ready">🎉 <?php esc_html_e( 'Your chatbot is live. Open your site and ask it something your content answers.', 'all-in-one-ai-chatbot' ); ?></p>
			<p><a class="button button-primary button-hero" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open my site', 'all-in-one-ai-chatbot' ); ?></a></p>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'The chatbot is not switched on yet: it needs a working API key.', 'all-in-one-ai-chatbot' ); ?>
				<a href="<?php echo esc_url( self::url( 1 ) ); ?>"><?php esc_html_e( 'Back to step 1', 'all-in-one-ai-chatbot' ); ?></a>
			</p></div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'When you are ready for more', 'all-in-one-ai-chatbot' ); ?></h3>
		<ul class="sai-wizard-next">
			<?php foreach ( $next as [ $url, $text, $label ] ) : ?>
				<li><a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $label ); ?></strong></a> — <?php echo esc_html( $text ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}

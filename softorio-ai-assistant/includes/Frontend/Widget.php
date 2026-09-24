<?php
/**
 * Loads the chat widget on the front end.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Frontend;

use Softorio\AiAssistant\Chat\PromptBuilder;
use Softorio\AiAssistant\Rest\ChatController;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the widget and hands it its configuration.
 */
final class Widget {

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Whether the widget should appear on this request.
	 */
	public static function should_show(): bool {
		$show = Settings::get( 'enabled', true ) && Settings::is_ready();

		if ( $show && Settings::get( 'hide_for_admins', false ) && current_user_can( 'manage_options' ) ) {
			$show = false;
		}

		/**
		 * Filter whether the chat widget is shown on the current page.
		 *
		 * @param bool $show Whether to show it.
		 */
		return (bool) apply_filters( 'softorio_ai_show_widget', $show );
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue(): void {
		if ( ! self::should_show() ) {
			return;
		}

		wp_enqueue_script(
			'softorio-ai-widget',
			SOFTORIO_AI_URL . 'assets/js/widget.js',
			array(),
			SOFTORIO_AI_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( 'softorio-ai-widget', 'window.softorioAiConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Everything the widget needs, including its translated strings.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$suggestions = array_values(
			array_filter(
				array_map( 'trim', explode( "\n", (string) Settings::get( 'suggestions', '' ) ) ),
				static fn( string $s ): bool => '' !== $s
			)
		);

		$whatsapp = PromptBuilder::whatsapp_number();
		$email    = sanitize_email( (string) Settings::get( 'contact_email', '' ) );
		$contact  = esc_url_raw( (string) Settings::get( 'contact_url', '' ) );

		$color = sanitize_hex_color( (string) Settings::get( 'color', '#2563eb' ) );

		return array(
			'restUrl'     => esc_url_raw( rest_url( ChatController::NAMESPACE . '/' ) ),
			'cssUrl'      => SOFTORIO_AI_URL . 'assets/css/widget.css?ver=' . rawurlencode( SOFTORIO_AI_VERSION ),
			'title'       => (string) Settings::get( 'assistant_name', '' ),
			'subtitle'    => Settings::company_name(),
			'greeting'    => (string) Settings::get( 'greeting', '' ),
			'color'       => $color ? $color : '#2563eb',
			'position'    => 'left' === Settings::get( 'position', 'right' ) ? 'left' : 'right',
			'suggestions' => array_slice( $suggestions, 0, 4 ),
			'maxLength'   => max( 50, (int) Settings::get( 'max_message_length', 1000 ) ),
			'contact'     => array(
				'whatsapp' => '' !== $whatsapp ? 'https://wa.me/' . $whatsapp : '',
				'email'    => '' !== $email ? 'mailto:' . $email : '',
				'url'      => $contact,
			),
			'i18n'        => array(
				'open'        => __( 'Open chat', 'softorio-ai-assistant' ),
				'close'       => __( 'Close chat', 'softorio-ai-assistant' ),
				'placeholder' => __( 'Type your question…', 'softorio-ai-assistant' ),
				'send'        => __( 'Send', 'softorio-ai-assistant' ),
				'typing'      => __( 'Assistant is typing', 'softorio-ai-assistant' ),
				'error'       => __( 'Sorry, something went wrong. Please try again.', 'softorio-ai-assistant' ),
				'offline'     => __( 'You seem to be offline. Check your connection and try again.', 'softorio-ai-assistant' ),
				'sources'     => __( 'Related pages', 'softorio-ai-assistant' ),
				'human'       => __( 'Talk to a person', 'softorio-ai-assistant' ),
				'whatsapp'    => __( 'WhatsApp', 'softorio-ai-assistant' ),
				'email'       => __( 'Email', 'softorio-ai-assistant' ),
				'contactPage' => __( 'Contact page', 'softorio-ai-assistant' ),
				'newChat'     => __( 'Start a new chat', 'softorio-ai-assistant' ),
				'disclaimer'  => __( 'AI assistant — answers may be imperfect.', 'softorio-ai-assistant' ),
			),
		);
	}
}

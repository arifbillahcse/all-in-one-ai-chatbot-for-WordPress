<?php
/**
 * Privacy policy text.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests privacy policy wording under Settings → Privacy.
 */
final class Privacy {

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	/**
	 * Register the suggested text.
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . esc_html__( 'This website uses an AI chat assistant. When you use it, the messages you type, and the address of the page you are on, are stored on this website and sent to our AI provider (for example OpenAI, Anthropic, Google, DeepSeek or OpenRouter, depending on configuration) to generate a reply. Please do not share passwords, payment card details or other sensitive information in the chat.', 'all-in-one-ai-chatbot' ) . '</p>'
			. '<p>' . esc_html__( 'Conversations are kept for a limited period and then deleted automatically. Your browser stores a random identifier so the chat can continue as you move between pages; it is not used for tracking or advertising. Your IP address is used briefly to prevent abuse and is not stored with your conversation.', 'all-in-one-ai-chatbot' ) . '</p>'
			. '<p>' . esc_html__( 'If you leave your contact details in the chat, they may be added to our customer relationship or email marketing service (such as HubSpot, Mailchimp or Brevo), together with your message. Deleting your data from this website does not delete it there; contact us to have it removed everywhere.', 'all-in-one-ai-chatbot' ) . '</p>';

		wp_add_privacy_policy_content( __( 'All in One AI Chatbot', 'all-in-one-ai-chatbot' ), wp_kses_post( $text ) );
	}
}

<?php
/**
 * Inline chat: shortcode and block.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the chat inside page content instead of in a floating bubble —
 * a "Contact" or "Help" page, say.
 *
 *     [ai_chatbot height="520"]
 *
 * or the "AI Chatbot" block. The page's floating bubble is skipped so the
 * visitor never sees two chats. Inline chats ignore the "where to show"
 * rules: placing one is itself the decision to show it.
 */
final class Inline {

	public const BLOCK = 'all-in-one-ai-chatbot/chat';

	/**
	 * Whether this request already has an inline chat.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Register shortcode and block.
	 */
	public static function init(): void {
		add_shortcode( 'ai_chatbot', array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'height' => 520 ), is_array( $atts ) ? $atts : array(), 'ai_chatbot' );

		return self::render( (int) $atts['height'] );
	}

	/**
	 * Register the block (server-rendered; the editor shows a placeholder).
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'softorio-ai-block',
			SOFTORIO_AI_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
			SOFTORIO_AI_VERSION,
			true
		);

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'AI Chatbot', 'all-in-one-ai-chatbot' ),
				'category'        => 'widgets',
				'icon'            => 'format-chat',
				'editor_script'   => 'softorio-ai-block',
				'attributes'      => array(
					'height' => array(
						'type'    => 'number',
						'default' => 520,
					),
				),
				'supports'        => array(
					'html'     => false,
					'multiple' => false,
				),
				'render_callback' => static fn( array $attributes ): string => self::render( (int) ( $attributes['height'] ?? 520 ) ),
			)
		);
	}

	/**
	 * The container the widget script fills in.
	 *
	 * @param int $height Height in pixels.
	 */
	public static function render( int $height ): string {
		// One chat per page: a second shortcode or block would be an empty box.
		if ( self::$rendered || ! Widget::available() ) {
			return '';
		}

		self::$rendered = true;
		Widget::load();

		$height = max( 320, min( 1200, $height ) );

		return sprintf(
			'<div class="aicb-inline" data-aicb-inline="1" style="height:%1$dpx;max-width:100%%"></div>',
			$height
		);
	}
}

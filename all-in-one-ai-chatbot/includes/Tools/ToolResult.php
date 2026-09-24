<?php
/**
 * What a tool hands back.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Data for the model, plus optional cards for the widget to render.
 *
 * The model only ever sees $data. Cards (product tiles, buttons) go to the
 * widget directly, built from real data, so a price or link shown on a card
 * cannot have been misread or invented by the model.
 */
final class ToolResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>             $data     Result for the model (JSON-encoded).
	 * @param array<int, array<string, mixed>> $cards    Cards for the widget.
	 * @param bool                             $is_error Whether the call failed.
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $cards = array(),
		public readonly bool $is_error = false,
	) {
	}

	/**
	 * A failure the model should explain to the visitor.
	 *
	 * @param string $message What went wrong, in plain words.
	 */
	public static function error( string $message ): self {
		return new self( array( 'error' => $message ), array(), true );
	}

	/**
	 * The model-facing content.
	 */
	public function content(): string {
		return (string) wp_json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

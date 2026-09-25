<?php
/**
 * Hides the "no answer" marker from streamed text.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Sits between the model's stream and the visitor.
 *
 * The model may open its reply with [NO_ANSWER]. When streaming, that marker
 * arrives in pieces ("[NO", "_ANS", "WER]"), so the start of the reply is
 * held back only until it is certain whether it is the marker — usually the
 * first chunk or two — and then everything flows straight through.
 */
final class MarkerFilter {

	private const MARKERS = array( '[no_answer]', '**[no_answer]**' );

	private string $head   = '';
	private bool $decided  = false;
	private bool $had_mark = false;
	private bool $trimming = false;

	/**
	 * Constructor.
	 *
	 * @param callable(string): void $out Receives visible text.
	 */
	public function __construct( private $out ) {
	}

	/**
	 * Take a piece of streamed text.
	 *
	 * @param string $delta Text from the model.
	 */
	public function push( string $delta ): void {
		if ( $this->decided ) {
			// Right after a marker, drop the space that separated it from
			// the reply, even when it arrives in a later piece.
			if ( $this->trimming ) {
				$delta = ltrim( $delta );

				if ( '' === $delta ) {
					return;
				}

				$this->trimming = false;
			}

			( $this->out )( $delta );
			return;
		}

		$this->head .= $delta;
		$trimmed     = strtolower( ltrim( $this->head ) );

		if ( '' === $trimmed ) {
			return;
		}

		foreach ( self::MARKERS as $marker ) {
			if ( str_starts_with( $trimmed, $marker ) ) {
				$this->had_mark = true;
				$this->decided  = true;
				$rest           = ltrim( substr( ltrim( $this->head ), strlen( $marker ) ) );

				if ( '' !== $rest ) {
					( $this->out )( $rest );
				} else {
					$this->trimming = true;
				}

				return;
			}

			// Still could become the marker: keep waiting.
			if ( str_starts_with( $marker, $trimmed ) ) {
				return;
			}
		}

		$this->decided = true;
		( $this->out )( $this->head );
	}

	/**
	 * End of the stream: release anything held back that was not a marker.
	 */
	public function finish(): void {
		if ( ! $this->decided && '' !== trim( $this->head ) ) {
			$this->decided = true;
			( $this->out )( $this->head );
		}
	}

	/**
	 * Whether the stream began with the marker.
	 */
	public function had_marker(): bool {
		return $this->had_mark;
	}
}

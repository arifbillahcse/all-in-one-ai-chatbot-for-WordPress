<?php
/**
 * Server-sent events parser.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an arbitrary sequence of network chunks into SSE events.
 *
 * Chunks do not respect line or event boundaries — one chunk can hold half
 * an event, or three — so bytes are buffered until a blank line ends an
 * event. Only `event:` and `data:` fields are used.
 */
final class SseParser {

	private string $buffer = '';
	private string $event  = '';

	/**
	 * Data lines of the event being read.
	 *
	 * @var array<int, string>
	 */
	private array $data = array();

	/**
	 * Constructor.
	 *
	 * @param callable(string, string): void $on_event Receives (event name, data).
	 */
	public function __construct( private $on_event ) {
	}

	/**
	 * Add bytes from the network.
	 *
	 * @param string $chunk Bytes.
	 */
	public function feed( string $chunk ): void {
		$this->buffer .= str_replace( "\r\n", "\n", $chunk );

		while ( false !== ( $pos = strpos( $this->buffer, "\n" ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- read line by line.
			$line         = substr( $this->buffer, 0, $pos );
			$this->buffer = substr( $this->buffer, $pos + 1 );
			$this->line( rtrim( $line, "\r" ) );
		}
	}

	/**
	 * Flush a final event that was not followed by a blank line.
	 */
	public function finish(): void {
		if ( '' !== $this->buffer ) {
			$this->line( $this->buffer );
			$this->buffer = '';
		}

		$this->dispatch();
	}

	/**
	 * Handle one line.
	 *
	 * @param string $line Line without newline.
	 */
	private function line( string $line ): void {
		if ( '' === $line ) {
			$this->dispatch();
			return;
		}

		if ( str_starts_with( $line, ':' ) ) {
			return; // Comment / keep-alive.
		}

		[ $field, $value ] = array_pad( explode( ':', $line, 2 ), 2, '' );
		$value             = str_starts_with( $value, ' ' ) ? substr( $value, 1 ) : $value;

		if ( 'event' === $field ) {
			$this->event = $value;
		} elseif ( 'data' === $field ) {
			$this->data[] = $value;
		}
	}

	/**
	 * Emit the collected event.
	 */
	private function dispatch(): void {
		if ( array() !== $this->data ) {
			( $this->on_event )( $this->event, implode( "\n", $this->data ) );
		}

		$this->event = '';
		$this->data  = array();
	}
}

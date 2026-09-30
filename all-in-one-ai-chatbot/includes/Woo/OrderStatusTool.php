<?php
/**
 * check_order_status tool.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Woo;

use Softorio\AiAssistant\Llm\ToolDefinition;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\RateLimiter;
use Softorio\AiAssistant\Support\Visitor;
use Softorio\AiAssistant\Tools\Tool;
use Softorio\AiAssistant\Tools\ToolContext;
use Softorio\AiAssistant\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Status of one order, for its owner only.
 *
 * Proof of ownership is checked here in code, never left to the model:
 *  - a logged-in customer may see orders placed on their account;
 *  - anyone else must match the order number with the billing email or
 *    phone (per the owner's setting).
 *
 * Every failure looks the same ("no order matches"), so a wrong number and a
 * wrong email cannot be told apart, and attempts are rate limited — together
 * that makes guessing someone else's order impractical.
 */
final class OrderStatusTool implements Tool {

	public const ATTEMPTS_PER_HOUR = 10;

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'check_order_status';
	}

	/**
	 * Definition for the model.
	 */
	public function definition(): ToolDefinition {
		return new ToolDefinition(
			$this->name(),
			'Look up the live status of an order: status, items, total, shipping and tracking. Needs the order number and, for visitors who are not logged in, the email address or phone number used at checkout. Only call it with details the visitor actually gave you.',
			array(
				'type'       => 'object',
				'properties' => array(
					'order_number' => array(
						'type'        => 'string',
						'description' => 'Order number as the visitor gave it, e.g. "1234" or "#1234".',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'Billing email address the visitor gave.',
					),
					'phone'        => array(
						'type'        => 'string',
						'description' => 'Billing phone number the visitor gave.',
					),
				),
				'required'   => array( 'order_number' ),
			)
		);
	}

	/**
	 * Offered to logged-in customers, and to guests unless guest lookups are off.
	 *
	 * @param ToolContext $context Context.
	 */
	public function available( ToolContext $context ): bool {
		return $context->logged_in() || 'none' !== Settings::get( 'woo_guest_verify', 'email_or_phone' );
	}

	/**
	 * Verify and look up.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param ToolContext          $context   Context.
	 */
	public function run( array $arguments, ToolContext $context ): ToolResult {
		$limit = RateLimiter::hit( Visitor::limit_key( '' !== $context->ip ? $context->ip : Visitor::ip() ) . '|order', self::ATTEMPTS_PER_HOUR, HOUR_IN_SECONDS );

		if ( ! $limit['allowed'] ) {
			return ToolResult::error( 'Too many order lookups from this connection. Ask the visitor to try again later or contact the shop.' );
		}

		$number = trim( ltrim( sanitize_text_field( (string) ( $arguments['order_number'] ?? '' ) ), '#' ) );
		$email  = strtolower( trim( sanitize_email( (string) ( $arguments['email'] ?? '' ) ) ) );
		$phone  = (string) preg_replace( '/\D+/', '', (string) ( $arguments['phone'] ?? '' ) );

		if ( '' === $number ) {
			return ToolResult::error( 'Ask the visitor for their order number.' );
		}

		$order = self::find( $number );
		$owner = $order instanceof \WC_Order && $context->logged_in() && $order->get_customer_id() === $context->user_id;

		if ( ! $owner && ! $context->logged_in() && '' === $email && '' === $phone ) {
			return ToolResult::error( self::missing_proof_message() );
		}

		if ( ! $order instanceof \WC_Order || ( ! $owner && ! self::guest_proves( $order, $email, $phone ) ) ) {
			Log::info( 'orders', 'Order lookup did not match' );

			return ToolResult::error( 'No order matches that order number and those details. Ask the visitor to check them, or to contact the shop.' );
		}

		return new ToolResult( array( 'order' => OrderData::summary( $order ) ), array( OrderData::card( $order, $context->user_id ) ) );
	}

	/**
	 * Find an order by the number a customer sees.
	 *
	 * @param string $number Order number.
	 */
	public static function find( string $number ): ?\WC_Order {
		$order = ctype_digit( $number ) ? wc_get_order( (int) $number ) : null;

		/**
		 * Filter the order for a customer-facing order number — for
		 * sequential/custom order number plugins where the number shown
		 * is not the internal id.
		 *
		 * @param \WC_Order|null $order  Order found by id, or null.
		 * @param string         $number Number as given.
		 */
		$order = apply_filters( 'softorio_ai_find_order', $order instanceof \WC_Order ? $order : null, $number );

		// Refunds are orders internally; never treat one as a customer order.
		return $order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund ? $order : null;
	}

	/**
	 * Whether the email or phone given matches the order, per settings.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $email Lower-cased email.
	 * @param string    $phone Digits only.
	 */
	public static function guest_proves( \WC_Order $order, string $email, string $phone ): bool {
		$mode = (string) Settings::get( 'woo_guest_verify', 'email_or_phone' );

		$email_ok = '' !== $email && hash_equals( strtolower( trim( (string) $order->get_billing_email() ) ), $email );
		$phone_ok = self::phones_match( (string) $order->get_billing_phone(), $phone );

		return match ( $mode ) {
			'email' => $email_ok,
			'phone' => $phone_ok,
			'none'  => false,
			default => $email_ok || $phone_ok,
		};
	}

	/**
	 * Compare phone numbers by their last digits, so "+880 1711-000000" and
	 * "01711000000" match. At least 7 digits are required.
	 *
	 * @param string $stored Phone on the order.
	 * @param string $given  Digits the visitor gave.
	 */
	public static function phones_match( string $stored, string $given ): bool {
		$stored = (string) preg_replace( '/\D+/', '', $stored );

		if ( strlen( $given ) < 7 || strlen( $stored ) < 7 ) {
			return false;
		}

		$length = min( 10, strlen( $stored ), strlen( $given ) );

		return hash_equals( substr( $stored, -$length ), substr( $given, -$length ) );
	}

	/**
	 * What to ask a guest for, per settings.
	 */
	private static function missing_proof_message(): string {
		return match ( (string) Settings::get( 'woo_guest_verify', 'email_or_phone' ) ) {
			'email' => 'Ask the visitor for the email address used at checkout.',
			'phone' => 'Ask the visitor for the phone number used at checkout.',
			default => 'Ask the visitor for the email address or phone number used at checkout.',
		};
	}
}

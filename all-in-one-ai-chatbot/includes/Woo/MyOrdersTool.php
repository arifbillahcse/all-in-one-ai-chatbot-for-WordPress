<?php
/**
 * list_my_orders tool.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Woo;

use Softorio\AiAssistant\Llm\ToolDefinition;
use Softorio\AiAssistant\Tools\Tool;
use Softorio\AiAssistant\Tools\ToolContext;
use Softorio\AiAssistant\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * A logged-in customer's recent orders. Never offered to guests.
 */
final class MyOrdersTool implements Tool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'list_my_orders';
	}

	/**
	 * Definition for the model.
	 */
	public function definition(): ToolDefinition {
		return new ToolDefinition(
			$this->name(),
			'List the logged-in customer\'s most recent orders with their status. Use when they ask about "my order" without giving a number.',
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);
	}

	/**
	 * Only for logged-in customers.
	 *
	 * @param ToolContext $context Context.
	 */
	public function available( ToolContext $context ): bool {
		return $context->logged_in();
	}

	/**
	 * List orders.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param ToolContext          $context   Context.
	 */
	public function run( array $arguments, ToolContext $context ): ToolResult {
		$orders = wc_get_orders(
			array(
				'customer_id' => $context->user_id,
				'limit'       => 5,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);

		$orders = array_filter( (array) $orders, static fn( $o ): bool => $o instanceof \WC_Order && $o->get_customer_id() === $context->user_id );

		if ( array() === $orders ) {
			return new ToolResult( array( 'orders' => array(), 'note' => 'This customer has no orders on their account.' ) );
		}

		return new ToolResult(
			array( 'orders' => array_values( array_map( array( OrderData::class, 'summary' ), $orders ) ) ),
			array_values( array_map( static fn( \WC_Order $o ): array => OrderData::card( $o, $context->user_id ), $orders ) )
		);
	}
}

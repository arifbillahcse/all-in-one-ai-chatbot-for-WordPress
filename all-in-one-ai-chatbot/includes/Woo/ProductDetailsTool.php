<?php
/**
 * get_product_details tool.
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
 * Full, live details of one product: variations, attributes, stock, size.
 */
final class ProductDetailsTool implements Tool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'get_product_details';
	}

	/**
	 * Definition for the model.
	 */
	public function definition(): ToolDefinition {
		return new ToolDefinition(
			$this->name(),
			'Get full live details of one product: description, variations with their prices and stock (sizes, colours), attributes, weight and dimensions. Use the id from search_products, or the product name.',
			array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => 'Product id from search_products.',
					),
					'name'       => array(
						'type'        => 'string',
						'description' => 'Product name, when the id is not known.',
					),
				),
			)
		);
	}

	/**
	 * Always available with product search.
	 *
	 * @param ToolContext $context Context.
	 */
	public function available( ToolContext $context ): bool {
		return true;
	}

	/**
	 * Look up the product.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param ToolContext          $context   Context.
	 */
	public function run( array $arguments, ToolContext $context ): ToolResult {
		$product = null;
		$id      = absint( $arguments['product_id'] ?? 0 );

		if ( $id > 0 ) {
			$product = wc_get_product( $id );

			// A variation id resolves to its parent, which is what a shopper sees.
			if ( $product instanceof \WC_Product_Variation ) {
				$product = wc_get_product( $product->get_parent_id() );
			}
		}

		if ( ! $product && '' !== trim( (string) ( $arguments['name'] ?? '' ) ) ) {
			$found   = wc_get_products(
				array(
					's'          => mb_substr( sanitize_text_field( (string) $arguments['name'] ), 0, 100 ),
					'status'     => 'publish',
					'visibility' => 'search',
					'limit'      => 1,
				)
			);
			$product = $found[0] ?? null;
		}

		if ( ! ProductData::visible( $product ) ) {
			return ToolResult::error( 'That product was not found in the shop.' );
		}

		return new ToolResult( array( 'product' => ProductData::details( $product ) ), array( ProductData::card( $product ) ) );
	}
}

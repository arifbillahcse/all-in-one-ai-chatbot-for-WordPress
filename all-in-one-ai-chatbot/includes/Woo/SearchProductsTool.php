<?php
/**
 * search_products tool.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Woo;

use Softorio\AiAssistant\Knowledge\Tokenizer;
use Softorio\AiAssistant\Llm\ToolDefinition;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Tools\Tool;
use Softorio\AiAssistant\Tools\ToolContext;
use Softorio\AiAssistant\Tools\ToolResult;

defined( 'ABSPATH' ) || exit;

/**
 * Finds products by words, category, price range and stock.
 */
final class SearchProductsTool implements Tool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'search_products';
	}

	/**
	 * Definition for the model.
	 */
	public function definition(): ToolDefinition {
		return new ToolDefinition(
			$this->name(),
			'Search the shop\'s products. Returns live names, prices, stock and short descriptions. Use for any product, price or availability question, and to recommend products. Use short keyword queries in the shop\'s language (e.g. "wireless headphones"), not whole sentences.',
			array(
				'type'       => 'object',
				'properties' => array(
					'query'         => array(
						'type'        => 'string',
						'description' => 'Keywords describing the product. Can be empty when filtering by category or price only.',
					),
					'category'      => array(
						'type'        => 'string',
						'description' => 'Optional category name to limit the search.',
					),
					'min_price'     => array(
						'type'        => 'number',
						'description' => 'Optional lowest price.',
					),
					'max_price'     => array(
						'type'        => 'number',
						'description' => 'Optional highest price (the visitor\'s budget).',
					),
					'in_stock_only' => array(
						'type'        => 'boolean',
						'description' => 'Only return products that are in stock.',
					),
					'sort'          => array(
						'type'        => 'string',
						'enum'        => array( 'relevance', 'price_low', 'price_high', 'popular', 'newest', 'rating' ),
						'description' => 'Order of results.',
					),
				),
			)
		);
	}

	/**
	 * Available when product search is on.
	 *
	 * @param ToolContext $context Context.
	 */
	public function available( ToolContext $context ): bool {
		return true;
	}

	/**
	 * Search.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param ToolContext          $context   Context.
	 */
	public function run( array $arguments, ToolContext $context ): ToolResult {
		$query    = mb_substr( trim( sanitize_text_field( (string) ( $arguments['query'] ?? '' ) ) ), 0, 100 );
		$category = self::category_slug( (string) ( $arguments['category'] ?? '' ) );
		$min      = is_numeric( $arguments['min_price'] ?? null ) ? max( 0.0, (float) $arguments['min_price'] ) : null;
		$max      = is_numeric( $arguments['max_price'] ?? null ) ? max( 0.0, (float) $arguments['max_price'] ) : null;
		$in_stock = ! empty( $arguments['in_stock_only'] ) || Settings::get( 'woo_hide_out_of_stock', false );
		$sort     = in_array( $arguments['sort'] ?? '', array( 'price_low', 'price_high', 'popular', 'newest', 'rating' ), true ) ? (string) $arguments['sort'] : 'relevance';
		$limit    = max( 1, min( 8, (int) Settings::get( 'woo_max_products', 4 ) ) );

		if ( '' === $query && '' === $category && null === $min && null === $max ) {
			return ToolResult::error( 'Give a search query, a category or a price range.' );
		}

		$products = $this->find( $query, $category, $in_stock, $sort );

		// Whole-phrase search found nothing: try the words one by one, so
		// "red running shoes size 42" still finds "Running Shoe (Red)".
		if ( array() === $products && '' !== $query ) {
			foreach ( Tokenizer::query_terms( $query, 4 ) as $term ) {
				foreach ( $this->find( $term, $category, $in_stock, $sort ) as $id => $product ) {
					$products[ $id ] = $product;
				}
			}
		}

		$products = array_filter(
			$products,
			static function ( \WC_Product $p ) use ( $min, $max ): bool {
				$price = (float) $p->get_price();

				return ProductData::visible( $p ) && ( null === $min || $price >= $min ) && ( null === $max || $price <= $max );
			}
		);

		$products = array_slice( $products, 0, $limit, true );

		if ( array() === $products ) {
			return new ToolResult(
				array(
					'products' => array(),
					'note'     => 'No matching products. Say so, suggest a broader search or a nearby alternative, or offer the contact options.',
				)
			);
		}

		return new ToolResult(
			array( 'products' => array_values( array_map( array( ProductData::class, 'summary' ), $products ) ) ),
			array_values( array_map( array( ProductData::class, 'card' ), $products ) )
		);
	}

	/**
	 * Query WooCommerce.
	 *
	 * @param string $query    Words.
	 * @param string $category Category slug.
	 * @param bool   $in_stock Stock filter.
	 * @param string $sort     Sort key.
	 * @return array<int, \WC_Product>
	 */
	private function find( string $query, string $category, bool $in_stock, string $sort ): array {
		$args = array(
			'status'     => 'publish',
			'limit'      => 30,
			'visibility' => '' !== $query ? 'search' : 'catalog',
			'return'     => 'objects',
		);

		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		if ( '' !== $category ) {
			$args['category'] = array( $category );
		}

		if ( $in_stock ) {
			$args['stock_status'] = 'instock';
		}

		$args += match ( $sort ) {
			'price_low'  => array(
				'orderby' => 'price',
				'order'   => 'ASC',
			),
			'price_high' => array(
				'orderby' => 'price',
				'order'   => 'DESC',
			),
			'popular'    => array(
				'orderby' => 'popularity',
				'order'   => 'DESC',
			),
			'rating'     => array(
				'orderby' => 'rating',
				'order'   => 'DESC',
			),
			'newest'     => array(
				'orderby' => 'date',
				'order'   => 'DESC',
			),
			default      => array(),
		};

		$out = array();

		foreach ( (array) wc_get_products( $args ) as $product ) {
			if ( $product instanceof \WC_Product ) {
				$out[ $product->get_id() ] = $product;
			}
		}

		return $out;
	}

	/**
	 * Category slug from what the model sent (a name or a slug).
	 *
	 * @param string $category Name or slug.
	 */
	private static function category_slug( string $category ): string {
		$category = trim( $category );

		if ( '' === $category ) {
			return '';
		}

		$term = get_term_by( 'slug', sanitize_title( $category ), 'product_cat' ) ?: get_term_by( 'name', $category, 'product_cat' );

		return $term instanceof \WP_Term ? $term->slug : '';
	}
}

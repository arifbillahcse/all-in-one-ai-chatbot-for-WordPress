<?php
/**
 * Product data for the model and for widget cards.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Woo;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes WooCommerce products into small, safe structures.
 *
 * Only products a shopper could see anyway are ever described: published,
 * not password protected, and visible in the catalogue or search.
 */
final class ProductData {

	/**
	 * Whether a product may be shown to a visitor.
	 *
	 * @param \WC_Product|false|null $product Product.
	 */
	public static function visible( mixed $product ): bool {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( 'publish' !== $product->get_status() || '' !== (string) get_post_field( 'post_password', $product->get_id() ) ) {
			return false;
		}

		if ( 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}

		if ( Settings::get( 'woo_hide_out_of_stock', false ) && ! $product->is_in_stock() ) {
			return false;
		}

		return true;
	}

	/**
	 * Price as plain text with currency, e.g. "৳15,000" or "৳12,000 (was ৳15,000)".
	 *
	 * Built from the numbers rather than get_price_html(): that HTML carries
	 * screen-reader text ("Original price was: …") which turns into gibberish
	 * once tags are stripped.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function price_text( \WC_Product $product ): string {
		$current = self::current_price( $product );
		$regular = self::regular_price( $product );

		return '' !== $regular ? $current . ' (was ' . $regular . ')' : $current;
	}

	/**
	 * Price the shopper pays now (a range for variable products), as displayed
	 * with the shop's tax settings.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function current_price( \WC_Product $product ): string {
		if ( $product instanceof \WC_Product_Variable ) {
			$min = (float) $product->get_variation_price( 'min', true );
			$max = (float) $product->get_variation_price( 'max', true );

			return $min === $max ? self::money( $min ) : self::money( $min ) . ' – ' . self::money( $max );
		}

		if ( '' === (string) $product->get_price() ) {
			return '';
		}

		return self::money( (float) wc_get_price_to_display( $product ) );
	}

	/**
	 * The pre-sale price when the product is on sale, else ''.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function regular_price( \WC_Product $product ): string {
		if ( ! $product->is_on_sale() || $product instanceof \WC_Product_Variable || '' === (string) $product->get_regular_price() ) {
			return '';
		}

		return self::money( (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) );
	}

	/**
	 * An amount in the shop currency, as plain text.
	 *
	 * @param float $amount Amount.
	 */
	private static function money( float $amount ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Stock in words.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function stock_text( \WC_Product $product ): string {
		if ( ! $product->is_in_stock() ) {
			return __( 'Out of stock', 'all-in-one-ai-chatbot' );
		}

		if ( $product->is_on_backorder() ) {
			return __( 'Available on backorder', 'all-in-one-ai-chatbot' );
		}

		$qty = $product->get_stock_quantity();

		if ( $product->managing_stock() && null !== $qty && $qty <= 5 ) {
			/* translators: %d: items left */
			return sprintf( __( 'Only %d left', 'all-in-one-ai-chatbot' ), $qty );
		}

		return __( 'In stock', 'all-in-one-ai-chatbot' );
	}

	/**
	 * Compact summary for search results.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function summary( \WC_Product $product ): array {
		$data = array(
			'id'          => $product->get_id(),
			'name'        => $product->get_name(),
			'price'       => self::price_text( $product ),
			'on_sale'     => $product->is_on_sale(),
			'stock'       => self::stock_text( $product ),
			'categories'  => wp_list_pluck( get_the_terms( $product->get_id(), 'product_cat' ) ?: array(), 'name' ),
			'description' => self::excerpt( $product, 220 ),
		);

		if ( $product->get_review_count() > 0 ) {
			$data['rating'] = round( (float) $product->get_average_rating(), 1 ) . '/5 (' . $product->get_review_count() . ' reviews)';
		}

		if ( $product->is_type( 'variable' ) ) {
			$data['options'] = self::attribute_options( $product );
		}

		return $data;
	}

	/**
	 * Full details for one product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function details( \WC_Product $product ): array {
		$data                = self::summary( $product );
		$data['description'] = self::excerpt( $product, 900, true );

		if ( '' !== $product->get_sku() ) {
			$data['sku'] = $product->get_sku();
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_visible() ) {
				$attributes[ wc_attribute_label( $attribute->get_name() ) ] = implode( ', ', self::attribute_values( $product, $attribute ) );
			}
		}
		if ( array() !== $attributes ) {
			$data['attributes'] = $attributes;
		}

		$dimensions = array_filter(
			array(
				'weight'     => $product->get_weight() ? $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' ) : '',
				'dimensions' => $product->has_dimensions() ? html_entity_decode( wc_format_dimensions( $product->get_dimensions( false ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '',
			)
		);
		$data      += $dimensions;

		if ( $product->is_type( 'variable' ) ) {
			$variations = array();

			foreach ( array_slice( $product->get_children(), 0, 25 ) as $child_id ) {
				$variation = wc_get_product( $child_id );

				if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
					continue;
				}

				$variations[] = array(
					'options' => wc_get_formatted_variation( $variation, true, false, false ),
					'price'   => self::price_text( $variation ),
					'stock'   => self::stock_text( $variation ),
				);
			}

			$data['variations'] = $variations;
		}

		return $data;
	}

	/**
	 * Card for the widget.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function card( \WC_Product $product ): array {
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
		$card  = array(
			'key'      => 'product-' . $product->get_id(),
			'type'     => 'product',
			'id'       => $product->get_id(),
			'name'     => $product->get_name(),
			'price'    => self::current_price( $product ),
			'was'      => self::regular_price( $product ),
			'stock'    => self::stock_text( $product ),
			'in_stock' => $product->is_in_stock(),
			'url'      => (string) get_permalink( $product->get_id() ),
			'image'    => $image ? $image : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
		);

		if ( Settings::get( 'woo_cart', true ) ) {
			$simple = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() && ! $product->is_sold_individually();

			// Simple products go straight into the cart; anything with
			// choices sends the shopper to the product page to pick them.
			$card['cart'] = $simple
				? array(
					'mode'  => 'ajax',
					'label' => __( 'Add to cart', 'all-in-one-ai-chatbot' ),
				)
				: array(
					'mode'  => 'link',
					'url'   => (string) get_permalink( $product->get_id() ),
					'label' => $product->is_type( 'variable' ) ? __( 'Choose options', 'all-in-one-ai-chatbot' ) : __( 'View product', 'all-in-one-ai-chatbot' ),
				);
		}

		return $card;
	}

	/**
	 * Plain-text description, trimmed.
	 *
	 * @param \WC_Product $product Product.
	 * @param int         $length  Characters.
	 * @param bool        $full    Use the long description too.
	 */
	private static function excerpt( \WC_Product $product, int $length, bool $full = false ): string {
		$text = $product->get_short_description();

		if ( $full || '' === trim( $text ) ) {
			$text .= "\n" . $product->get_description();
		}

		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );

		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . '…' : $text;
	}

	/**
	 * Values of a product attribute.
	 *
	 * @param \WC_Product           $product   Product.
	 * @param \WC_Product_Attribute $attribute Attribute.
	 * @return array<int, string>
	 */
	private static function attribute_values( \WC_Product $product, \WC_Product_Attribute $attribute ): array {
		$values = $attribute->is_taxonomy()
			? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
			: $attribute->get_options();

		return array_map( 'strval', (array) $values );
	}

	/**
	 * "Colour: Red, Blue; Size: M, L" for variable products.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function attribute_options( \WC_Product $product ): string {
		$parts = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_variation() ) {
				$parts[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', self::attribute_values( $product, $attribute ) );
			}
		}

		return implode( '; ', $parts );
	}
}

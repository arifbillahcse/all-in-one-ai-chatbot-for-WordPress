<?php
/**
 * WooCommerce integration.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Woo;

use Softorio\AiAssistant\Cron;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the assistant into a shop assistant when WooCommerce is active.
 *
 * Adds a settings tab, the product and order tools, shop-specific prompt
 * rules and richer product text in the search index. Nothing here loads on
 * sites without WooCommerce.
 */
final class WooModule {

	/**
	 * Hook up, only when WooCommerce is present.
	 */
	public static function init(): void {
		if ( ! self::woocommerce_active() ) {
			return;
		}

		add_filter( 'softorio_ai_settings_tabs', array( self::class, 'tabs' ) );
		add_filter( 'softorio_ai_settings_sections', array( self::class, 'sections' ) );
		add_filter( 'softorio_ai_settings_fields', array( self::class, 'fields' ) );
		add_action( 'softorio_ai_settings_changed', array( self::class, 'settings_changed' ), 10, 2 );

		// Registered unconditionally; each callback checks the switch when
		// it runs, so turning the feature on or off applies immediately.
		add_filter( 'softorio_ai_tools', array( self::class, 'tools' ) );
		add_filter( 'softorio_ai_prompt_parts', array( self::class, 'prompt' ), 10, 2 );
		add_filter( 'softorio_ai_post_types', array( self::class, 'index_products' ) );
		add_filter( 'softorio_ai_index_content', array( self::class, 'product_index_text' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Whether the owner switched the shop assistant on.
	 */
	public static function enabled(): bool {
		return self::woocommerce_active() && (bool) Settings::get( 'woo_enabled', false );
	}

	/**
	 * Settings tab.
	 *
	 * @param array<string, string> $tabs Tabs.
	 * @return array<string, string>
	 */
	public static function tabs( array $tabs ): array {
		$out = array();

		foreach ( $tabs as $slug => $label ) {
			$out[ $slug ] = $label;

			if ( 'knowledge' === $slug ) {
				$out['woocommerce'] = 'WooCommerce';
			}
		}

		return $out;
	}

	/**
	 * Settings sections.
	 *
	 * @param array<string, array<string, string>> $sections Sections.
	 * @return array<string, array<string, string>>
	 */
	public static function sections( array $sections ): array {
		$sections['woocommerce.main']     = array(
			'title' => __( 'Shop assistant', 'all-in-one-ai-chatbot' ),
			'desc'  => __( 'Let the assistant search your products, show product cards with Add to cart buttons, and tell customers where their order is — using live shop data, never guesses.', 'all-in-one-ai-chatbot' ),
		);
		$sections['woocommerce.products'] = array( 'title' => __( 'Products', 'all-in-one-ai-chatbot' ) );
		$sections['woocommerce.orders']   = array(
			'title' => __( 'Order tracking', 'all-in-one-ai-chatbot' ),
			'desc'  => __( 'Logged-in customers can ask about their own orders. Guests must give the order number plus the email or phone used at checkout. Failed attempts are rate limited, so order numbers cannot be guessed.', 'all-in-one-ai-chatbot' ),
		);

		return $sections;
	}

	/**
	 * Settings fields.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( array $fields ): array {
		return $fields + array(
			'woo_enabled'           => array(
				'tab'     => 'woocommerce',
				'section' => 'main',
				'type'    => 'checkbox',
				'label'   => __( 'Status', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Use live shop data in the chat', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'woo_products'          => array(
				'tab'     => 'woocommerce',
				'section' => 'products',
				'type'    => 'checkbox',
				'label'   => __( 'Product search', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Find and recommend products, with live prices and stock', 'all-in-one-ai-chatbot' ),
				'default' => true,
			),
			'woo_cart'              => array(
				'tab'     => 'woocommerce',
				'section' => 'products',
				'type'    => 'checkbox',
				'label'   => __( 'Add to cart', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Show "Add to cart" buttons on product cards in the chat', 'all-in-one-ai-chatbot' ),
				'default' => true,
			),
			'woo_hide_out_of_stock' => array(
				'tab'     => 'woocommerce',
				'section' => 'products',
				'type'    => 'checkbox',
				'label'   => __( 'Out of stock', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Never recommend out-of-stock products', 'all-in-one-ai-chatbot' ),
				'default' => false,
			),
			'woo_max_products'      => array(
				'tab'     => 'woocommerce',
				'section' => 'products',
				'type'    => 'number',
				'label'   => __( 'Products per answer', 'all-in-one-ai-chatbot' ),
				'default' => 4,
				'min'     => 1,
				'max'     => 8,
			),
			'woo_orders'            => array(
				'tab'     => 'woocommerce',
				'section' => 'orders',
				'type'    => 'checkbox',
				'label'   => __( 'Order tracking', 'all-in-one-ai-chatbot' ),
				'desc'    => __( 'Let customers check the status of their orders in the chat', 'all-in-one-ai-chatbot' ),
				'default' => true,
			),
			'woo_guest_verify'      => array(
				'tab'     => 'woocommerce',
				'section' => 'orders',
				'type'    => 'select',
				'label'   => __( 'Guests prove the order is theirs with', 'all-in-one-ai-chatbot' ),
				'options' => array(
					'email_or_phone' => __( 'Billing email or phone', 'all-in-one-ai-chatbot' ),
					'email'          => __( 'Billing email only', 'all-in-one-ai-chatbot' ),
					'phone'          => __( 'Billing phone only', 'all-in-one-ai-chatbot' ),
					'none'           => __( 'Nothing — only logged-in customers can check orders', 'all-in-one-ai-chatbot' ),
				),
				'default' => 'email_or_phone',
			),
		);
	}

	/**
	 * Rebuild the index when the shop assistant is switched on or off, so
	 * products enter or leave it.
	 *
	 * @param array<string, mixed> $old Previous settings.
	 * @param array<string, mixed> $saved New settings.
	 */
	public static function settings_changed( array $old, array $saved ): void {
		if ( (bool) ( $old['woo_enabled'] ?? false ) !== (bool) ( $saved['woo_enabled'] ?? false ) ) {
			update_option( Indexer::STATE_OPTION, array( 'status' => 'pending' ), false );
			Cron::queue_build( 0 );
		}
	}

	/**
	 * The tools, per the owner's switches.
	 *
	 * @param array<int, \Softorio\AiAssistant\Tools\Tool> $tools Tools.
	 * @return array<int, \Softorio\AiAssistant\Tools\Tool>
	 */
	public static function tools( array $tools ): array {
		if ( ! self::enabled() ) {
			return $tools;
		}

		if ( Settings::get( 'woo_products', true ) ) {
			$tools[] = new SearchProductsTool();
			$tools[] = new ProductDetailsTool();
		}

		if ( Settings::get( 'woo_orders', true ) ) {
			$tools[] = new OrderStatusTool();
			$tools[] = new MyOrdersTool();
		}

		return $tools;
	}

	/**
	 * Shop rules for the model, when shop tools are offered.
	 *
	 * @param array<int, string> $parts Prompt sections.
	 * @param array<int, string> $tools Offered tool names.
	 * @return array<int, string>
	 */
	public static function prompt( array $parts, array $tools ): array {
		if ( ! self::enabled() ) {
			return $parts;
		}

		$rules = array();

		if ( in_array( 'search_products', $tools, true ) ) {
			$rules[] = '- For any question about products, prices, sizes, colours, stock or availability, call search_products (and get_product_details for specifics). Never state a price, discount or stock level that did not come from these tools in this conversation.';
			$rules[] = '- Product cards with photos, prices and buttons are shown to the visitor automatically below your reply for the products you mention. Refer to products by name, keep the text short, and do not paste product links.';
			$rules[] = '- When recommending, ask one short question if the need is unclear (budget, use, size), otherwise suggest the best 1–3 matches and say why each fits.';
			$rules[] = '- If the visitor wants to buy, tell them to use the "Add to cart" button on the card. You cannot place orders or take payment.';
		}

		if ( in_array( 'check_order_status', $tools, true ) ) {
			$verify = (string) Settings::get( 'woo_guest_verify', 'email_or_phone' );
			$proof  = match ( $verify ) {
				'email' => 'the email address used at checkout',
				'phone' => 'the phone number used at checkout',
				'none'  => '',
				default => 'the email address or phone number used at checkout',
			};
			$rules[] = '' === $proof
				? '- Order status is only available to logged-in customers. Ask guests to log in to their account first.'
				: '- To check an order, you need the order number and ' . $proof . ', unless the visitor is logged in (then list_my_orders or the number alone is enough). Ask for what is missing, then call check_order_status. Never guess, and never reveal order details the tool did not return.';
		}

		if ( array() !== $rules ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
			$parts[]  = "Shop assistant rules:\n" . implode( "\n", $rules ) . ( '' !== $currency ? "\n- Prices are in " . $currency . '.' : '' );
		}

		return $parts;
	}

	/**
	 * Index products when the shop assistant is on.
	 *
	 * @param array<int, string> $types Post types.
	 * @return array<int, string>
	 */
	public static function index_products( array $types ): array {
		if ( self::enabled() && Settings::get( 'woo_products', true ) && ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}

		return $types;
	}

	/**
	 * Richer index text for products: what a visitor might search by.
	 *
	 * Prices and stock are deliberately left out: they change, and the tools
	 * read them live, so the index can never serve a stale price.
	 *
	 * @param string   $body Text to index.
	 * @param \WP_Post $post Post.
	 */
	public static function product_index_text( string $body, \WP_Post $post ): string {
		if ( 'product' !== $post->post_type || ! self::enabled() ) {
			return $body;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return $body;
		}

		$lines = array();

		$categories = wp_list_pluck( get_the_terms( $post->ID, 'product_cat' ) ?: array(), 'name' );
		if ( array() !== $categories ) {
			$lines[] = 'Category: ' . implode( ', ', $categories );
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_visible() ) {
				$values  = $attribute->is_taxonomy() ? wc_get_product_terms( $post->ID, $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
				$lines[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', array_map( 'strval', (array) $values ) );
			}
		}

		if ( '' !== $product->get_sku() ) {
			$lines[] = 'SKU: ' . $product->get_sku();
		}

		return trim( implode( "\n", $lines ) . "\n\n" . $body );
	}
}

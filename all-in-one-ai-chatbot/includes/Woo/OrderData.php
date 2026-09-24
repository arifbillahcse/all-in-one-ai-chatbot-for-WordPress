<?php
/**
 * Order data for the model and cards.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes an order into what a customer needs to know about it — and no more.
 *
 * Deliberately left out: full addresses, the customer's email and phone,
 * payment details and internal (private) order notes. The customer already
 * knows their own contact details, and a transcript can be emailed or
 * exported later.
 */
final class OrderData {

	/**
	 * Summary for the model.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function summary( \WC_Order $order ): array {
		$items = array();

		foreach ( array_slice( $order->get_items(), 0, 10 ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$items[] = $item->get_name() . ' × ' . $item->get_quantity();
			}
		}

		$data = array(
			'order_number'    => (string) $order->get_order_number(),
			'status'          => wc_get_order_status_name( $order->get_status() ),
			'what_it_means'   => self::status_meaning( $order->get_status() ),
			'placed_on'       => $order->get_date_created() ? wp_date( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ) : '',
			'items'           => $items,
			'total'           => self::money( (float) $order->get_total(), $order->get_currency() ),
			'payment_method'  => $order->get_payment_method_title(),
			'shipping_method' => $order->get_shipping_method(),
			'ship_to_city'    => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
		);

		$tracking = self::tracking( $order );
		if ( array() !== $tracking ) {
			$data['tracking'] = $tracking;
		}

		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'type' => 'customer', 'limit' => 3 ) ) as $note ) {
			$notes[] = wp_strip_all_tags( (string) $note->content );
		}
		if ( array() !== $notes ) {
			$data['updates_from_shop'] = $notes;
		}

		return array_filter( $data, static fn( $v ) => '' !== $v && array() !== $v );
	}

	/**
	 * Card for the widget.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $user_id Viewer, to decide whether to link to My Account.
	 * @return array<string, mixed>
	 */
	public static function card( \WC_Order $order, int $user_id ): array {
		$card = array(
			'key'        => 'order-' . $order->get_id(),
			'type'       => 'order',
			'number'     => (string) $order->get_order_number(),
			'status'     => wc_get_order_status_name( $order->get_status() ),
			'status_key' => $order->get_status(),
			'total'      => self::money( (float) $order->get_total(), $order->get_currency() ),
			'date'       => $order->get_date_created() ? wp_date( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ) : '',
			'items'      => count( $order->get_items() ),
		);

		if ( $user_id > 0 && $order->get_customer_id() === $user_id ) {
			$card['url'] = $order->get_view_order_url();
		}

		$tracking = self::tracking( $order );
		if ( ! empty( $tracking[0]['url'] ) ) {
			$card['tracking_url'] = $tracking[0]['url'];
		}

		return $card;
	}

	/**
	 * Plain-language meaning of each core status.
	 *
	 * @param string $status Status slug.
	 */
	private static function status_meaning( string $status ): string {
		return match ( $status ) {
			'pending'    => 'Order received, waiting for payment.',
			'on-hold'    => 'Waiting for confirmation (often payment verification) before it is processed.',
			'processing' => 'Paid and being prepared for shipping.',
			'completed'  => 'Fulfilled / shipped.',
			'cancelled'  => 'Cancelled.',
			'refunded'   => 'Refunded.',
			'failed'     => 'Payment failed; the order was not placed.',
			default      => '',
		};
	}

	/**
	 * Shipment tracking, from the common tracking plugins' order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{provider: string, number: string, url: string}>
	 */
	private static function tracking( \WC_Order $order ): array {
		$out = array();

		// WooCommerce Shipment Tracking (and compatible plugins).
		foreach ( (array) $order->get_meta( '_wc_shipment_tracking_items' ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['tracking_number'] ) ) {
				$out[] = array(
					'provider' => (string) ( $item['custom_tracking_provider'] ?? '' ) ?: (string) ( $item['tracking_provider'] ?? '' ),
					'number'   => (string) $item['tracking_number'],
					'url'      => esc_url_raw( (string) ( $item['custom_tracking_link'] ?? '' ) ),
				);
			}
		}

		/**
		 * Filter shipment tracking for an order (for courier plugins that
		 * store it differently, e.g. Pathao, Steadfast, RedX).
		 *
		 * @param array     $out   [{provider, number, url}].
		 * @param \WC_Order $order Order.
		 */
		return (array) apply_filters( 'softorio_ai_order_tracking', $out, $order );
	}

	/**
	 * Money as plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 */
	private static function money( float $amount, string $currency ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}

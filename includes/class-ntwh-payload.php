<?php
/**
 * Builds the JSON sent to endpoints.
 *
 * Money values are strings (like the WooCommerce REST API) and lists are JSON arrays,
 * so the body survives JSON.parse / JSON.stringify unchanged in n8n (needed for signature checks).
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payload builder.
 */
final class NTWH_Payload {

	/** JSON flags shared by every request body. */
	const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

	/**
	 * Full envelope for an event.
	 *
	 * @param string $event       Event.
	 * @param string $object_type order|customer|product|test.
	 * @param int    $object_id   Object ID.
	 * @param array  $context     Extra info (e.g. from/to status).
	 * @param string $uuid        Event ID.
	 * @return array|null Null when the object no longer exists.
	 */
	public static function envelope( $event, $object_type, $object_id, $context, $uuid ) {
		$data = self::object_data( $object_type, $object_id );
		if ( null === $data ) {
			return null;
		}
		$env = array(
			'id'         => $uuid,
			'event'      => $event,
			'created_at' => gmdate( 'c' ),
			'site'       => array(
				'url'  => home_url( '/' ),
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			'context'    => (object) self::normalize( (array) $context ),
			'data'       => $data,
		);
		$env = (array) apply_filters( 'ntwh_envelope', $env, $event, $object_type, $object_id );
		return self::normalize( $env );
	}

	/**
	 * Data for an object.
	 *
	 * @param string $type Type.
	 * @param int    $id   ID.
	 * @return array|null
	 */
	public static function object_data( $type, $id ) {
		switch ( $type ) {
			case 'order':
				$order = wc_get_order( $id );
				return $order ? self::order( $order ) : null;
			case 'customer':
				return self::customer( $id );
			case 'product':
				$p = wc_get_product( $id );
				return $p ? self::product( $p ) : null;
			case 'test':
				return self::sample();
		}
		$custom = apply_filters( 'ntwh_object_data', null, $type, $id );
		return is_array( $custom ) ? $custom : null;
	}

	/**
	 * Money as string.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function money( $v ) {
		return wc_format_decimal( (string) $v, wc_get_price_decimals() );
	}

	/**
	 * Date as ISO 8601 or null.
	 *
	 * @param WC_DateTime|null $d Date.
	 * @return string|null
	 */
	private static function date( $d ) {
		return $d ? $d->date( 'c' ) : null;
	}

	/**
	 * Order data.
	 *
	 * @param WC_Order $o Order.
	 * @return array
	 */
	public static function order( $o ) {
		$items = array();
		foreach ( $o->get_items() as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$items[] = array(
				'id'           => (int) $item_id,
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'sku'          => $product ? (string) $product->get_sku() : '',
				'name'         => $item->get_name(),
				'quantity'     => (int) $item->get_quantity(),
				'subtotal'     => self::money( $item->get_subtotal() ),
				'total'        => self::money( $item->get_total() ),
				'tax'          => self::money( $item->get_total_tax() ),
			);
		}
		$shipping_lines = array();
		foreach ( $o->get_shipping_methods() as $s ) {
			$shipping_lines[] = array(
				'method_id' => (string) $s->get_method_id(),
				'title'     => (string) $s->get_name(),
				'total'     => self::money( $s->get_total() ),
			);
		}
		$customer_id = (int) $o->get_customer_id();

		return array(
			'id'                   => (int) $o->get_id(),
			'number'               => (string) $o->get_order_number(),
			'status'               => (string) $o->get_status(),
			'currency'             => (string) $o->get_currency(),
			'total'                => self::money( $o->get_total() ),
			'subtotal'             => self::money( $o->get_subtotal() ),
			'total_tax'            => self::money( $o->get_total_tax() ),
			'shipping_total'       => self::money( $o->get_shipping_total() ),
			'discount_total'       => self::money( $o->get_discount_total() ),
			'total_refunded'       => self::money( $o->get_total_refunded() ),
			'payment_method'       => (string) $o->get_payment_method(),
			'payment_method_title' => (string) $o->get_payment_method_title(),
			'transaction_id'       => (string) $o->get_transaction_id(),
			'is_paid'              => (bool) $o->is_paid(),
			'date_created'         => self::date( $o->get_date_created() ),
			'date_paid'            => self::date( $o->get_date_paid() ),
			'date_completed'       => self::date( $o->get_date_completed() ),
			'customer_note'        => (string) $o->get_customer_note(),
			'customer'             => array(
				'id'         => $customer_id,
				'email'      => (string) $o->get_billing_email(),
				'first_name' => (string) $o->get_billing_first_name(),
				'last_name'  => (string) $o->get_billing_last_name(),
				'phone'      => (string) $o->get_billing_phone(),
				'is_guest'   => 0 === $customer_id,
			),
			'billing'              => self::address( $o, 'billing' ),
			'shipping'             => self::address( $o, 'shipping' ),
			'items'                => $items,
			'shipping_lines'       => $shipping_lines,
			'coupons'              => array_values( array_map( 'strval', $o->get_coupon_codes() ) ),
			'links'                => array(
				'admin'    => $o->get_edit_order_url(),
				'customer' => $o->get_view_order_url(),
			),
		);
	}

	/**
	 * Address block.
	 *
	 * @param WC_Order $o    Order.
	 * @param string   $type billing|shipping.
	 * @return array
	 */
	private static function address( $o, $type ) {
		$out = array();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $f ) {
			$getter    = 'get_' . $type . '_' . $f;
			$out[ $f ] = is_callable( array( $o, $getter ) ) ? (string) $o->$getter() : '';
		}
		if ( 'billing' === $type ) {
			$out['email'] = (string) $o->get_billing_email();
		}
		return $out;
	}

	/**
	 * Customer data.
	 *
	 * @param int $id User ID.
	 * @return array|null
	 */
	public static function customer( $id ) {
		$user = get_userdata( $id );
		if ( ! $user ) {
			return null;
		}
		$c = new WC_Customer( $id );
		return array(
			'id'           => (int) $id,
			'email'        => (string) $user->user_email,
			'username'     => (string) $user->user_login,
			'first_name'   => (string) $c->get_first_name(),
			'last_name'    => (string) $c->get_last_name(),
			'phone'        => (string) $c->get_billing_phone(),
			'country'      => (string) $c->get_billing_country(),
			'date_created' => self::date( $c->get_date_created() ),
		);
	}

	/**
	 * Product data.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	public static function product( $p ) {
		$qty = $p->get_stock_quantity();
		return array(
			'id'             => (int) $p->get_id(),
			'parent_id'      => (int) $p->get_parent_id(),
			'name'           => (string) $p->get_name(),
			'sku'            => (string) $p->get_sku(),
			'type'           => (string) $p->get_type(),
			'price'          => self::money( $p->get_price() ),
			'stock_status'   => (string) $p->get_stock_status(),
			'stock_quantity' => null === $qty ? null : (string) wc_stock_amount( $qty ),
			'low_stock_at'   => (string) wc_get_low_stock_amount( $p ),
			'links'          => array(
				// Built by hand: get_edit_post_link() is empty in background jobs (no logged-in user).
				'admin'   => admin_url( 'post.php?post=' . ( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() ) . '&action=edit' ),
				'product' => (string) get_permalink( $p->get_id() ),
			),
		);
	}

	/**
	 * Sample data for "Send test" (the newest real order when there is one).
	 *
	 * @return array
	 */
	public static function sample() {
		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
			)
		);
		if ( $orders ) {
			$d                = self::order( $orders[0] );
			$d['_test_note'] = 'This is a test delivery from NodeThemes Webhooks using your latest order.';
			return $d;
		}
		return array(
			'_test_note' => 'This is a test delivery from NodeThemes Webhooks. Your store has no orders yet, so this sample has no order data.',
			'id'         => 0,
			'number'     => 'TEST',
			'status'     => 'processing',
			'currency'   => get_woocommerce_currency(),
			'total'      => self::money( 0 ),
			'items'      => array(),
		);
	}

	/**
	 * Make the structure JSON-stable: floats become strings, keyed arrays stay objects,
	 * numeric-keyed lists stay lists.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public static function normalize( $v ) {
		if ( is_float( $v ) ) {
			return wc_format_decimal( (string) $v );
		}
		if ( is_object( $v ) ) {
			$arr = get_object_vars( $v );
			return $arr ? (object) array_map( array( __CLASS__, 'normalize' ), $arr ) : new stdClass();
		}
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'normalize' ), $v );
		}
		return $v;
	}

	/**
	 * Encode a body.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function encode( $data ) {
		$json = wp_json_encode( $data, self::JSON_FLAGS );
		return false === $json ? '{}' : $json;
	}
}

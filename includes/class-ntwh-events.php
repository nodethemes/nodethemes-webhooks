<?php
/**
 * WooCommerce hooks -> events.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event sources (free events). The Pro add-on emits the Pro events with NTWH_Events::emit().
 */
final class NTWH_Events {

	/**
	 * Events already emitted in this request (event|object) to avoid doubles.
	 *
	 * @var array
	 */
	private static $seen = array();

	/**
	 * Boot.
	 */
	public static function init() {
		// WooCommerce fires this once per order, also when a draft (block checkout) becomes a real order.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'new_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 20, 4 );
	}

	/**
	 * Emit an event (queues it for every subscribed endpoint).
	 *
	 * @param string $event       Event.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $context     Context.
	 * @param bool   $freeze_now  Build the payload now instead of at the end of the request.
	 * @return int[] Delivery IDs.
	 */
	public static function emit( $event, $object_type, $object_id, $context = array(), $freeze_now = false ) {
		$key = $event . '|' . $object_type . '|' . $object_id . '|' . md5( wp_json_encode( $context ) );
		if ( isset( self::$seen[ $key ] ) ) {
			return array();
		}
		self::$seen[ $key ] = true;
		if ( ! apply_filters( 'ntwh_emit', true, $event, $object_type, $object_id, $context ) ) {
			return array();
		}
		return NTWH_Dispatcher::queue( $event, $object_type, $object_id, $context, array(), $freeze_now );
	}

	/**
	 * Draft statuses that are not real orders yet.
	 *
	 * @return string[]
	 */
	private static function drafts() {
		return array( 'auto-draft', 'draft', 'checkout-draft' );
	}

	/**
	 * Is it a normal shop order (not a refund / subscription etc.)?
	 *
	 * @param WC_Order|null $order Order.
	 * @return bool
	 */
	private static function is_shop_order( $order ) {
		return $order instanceof WC_Order && 'shop_order' === $order->get_type();
	}

	/**
	 * Fire a once-per-order event, remembered in order meta.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $event Event.
	 * @param string   $meta  Meta key.
	 * @param array    $ctx   Context.
	 */
	private static function once( $order, $event, $meta, $ctx = array() ) {
		if ( $order->get_meta( $meta ) ) {
			return;
		}
		$order->update_meta_data( $meta, time() );
		$order->save_meta_data();
		self::emit( $event, 'order', $order->get_id(), $ctx );
	}

	/**
	 * order.created
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order.
	 */
	public static function new_order( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! self::is_shop_order( $order ) || in_array( $order->get_status( 'edit' ), self::drafts(), true ) ) {
			return;
		}
		self::once( $order, 'order.created', '_ntwh_created', array( 'status' => $order->get_status() ) );
	}

	/**
	 * order.status_changed, order.paid, order.completed, order.cancelled
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! self::is_shop_order( $order ) || in_array( $to, self::drafts(), true ) ) {
			return;
		}
		$ctx = array(
			'from' => (string) $from,
			'to'   => (string) $to,
		);
		// A draft becoming a real order is "created", not a status change.
		if ( in_array( $from, self::drafts(), true ) ) {
			self::once( $order, 'order.created', '_ntwh_created', array( 'status' => $to ) );
		} else {
			self::emit( 'order.status_changed', 'order', $order->get_id(), $ctx );
		}
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			self::once( $order, 'order.paid', '_ntwh_paid', $ctx );
		}
		if ( 'completed' === $to ) {
			self::emit( 'order.completed', 'order', $order->get_id(), $ctx );
		}
		if ( 'cancelled' === $to ) {
			self::emit( 'order.cancelled', 'order', $order->get_id(), $ctx );
		}
	}
}

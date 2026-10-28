<?php
/**
 * "Webhooks" box on the order edit screen (classic and HPOS).
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order meta box.
 */
final class NTWH_Order_Box {

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 30 );
	}

	/**
	 * Register the box on both order screens.
	 */
	public static function add() {
		if ( ! current_user_can( NTWH_Admin::cap() ) ) {
			return;
		}
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'ntwh-order-box', __( 'Webhooks', 'nodethemes-webhooks' ), array( __CLASS__, 'render' ), $screen, 'side', 'low' );
		}
	}

	/**
	 * Render.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		list( $rows ) = NTWH_DB::query(
			array(
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				'per_page'    => 15,
			)
		);
		$endpoints = NTWH_Endpoints::all();
		echo '<div class="ntwh-orderbox">';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No webhooks were sent for this order yet.', 'nodethemes-webhooks' ) . '</p>';
		} else {
			echo '<ul style="margin:0">';
			foreach ( $rows as $r ) {
				$name = isset( $endpoints[ $r->endpoint_id ] ) ? $endpoints[ $r->endpoint_id ]['name'] : '—';
				printf(
					'<li style="margin:0 0 8px;line-height:1.5"><a href="%1$s">%2$s</a> <code>%3$s</code><br><small>%4$s · %5$s</small></li>',
					esc_url( admin_url( 'admin.php?page=ntwh-deliveries&open=' . (int) $r->id . '&object_id=' . (int) $order->get_id() ) ),
					wp_kses_post( NTWH_Admin::badge( $r->status ) ),
					esc_html( $r->event ),
					esc_html( $name ),
					esc_html( NTWH_Admin::when( $r->created ) )
				);
			}
			echo '</ul>';
		}
		if ( NTWH_Pro::active() ) {
			do_action( 'ntwh_order_box_pro', $order );
		} else {
			echo '<p>' . NTWH_Pro::lock( __( 'Send again', 'nodethemes-webhooks' ), 'order-send', 'button' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<style>#ntwh-order-box .ntwh-badge{display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;background:#eef2ff;color:#3730a3}#ntwh-order-box .is-success{background:#dcfce7;color:#166534}#ntwh-order-box .is-failed{background:#fee2e2;color:#991b1b}#ntwh-order-box .is-retrying,#ntwh-order-box .is-queued,#ntwh-order-box .is-sending{background:#fef3c7;color:#92400e}#ntwh-order-box .is-skipped{background:#f1f5f9;color:#475569}#ntwh-order-box .ntwh-protag{font-size:9px;font-weight:700;background:#f59e0b;color:#fff;border-radius:3px;padding:1px 4px}</style>';
		echo '</div>';
	}
}

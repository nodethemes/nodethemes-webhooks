<?php
/**
 * Endpoints (where events are sent) and the event catalogue.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint storage.
 */
final class NTWH_Endpoints {

	const OPTION = 'ntwh_endpoints';
	const HEALTH = 'ntwh_health';

	/**
	 * Event catalogue. "pro" events are emitted by the Pro add-on.
	 *
	 * @return array event => [ label, group, pro ]
	 */
	public static function events() {
		$events = array(
			'order.created'        => array( __( 'Order created', 'nodethemes-webhooks' ), 'order', false ),
			'order.paid'           => array( __( 'Order paid (once per order)', 'nodethemes-webhooks' ), 'order', false ),
			'order.status_changed' => array( __( 'Order status changed', 'nodethemes-webhooks' ), 'order', false ),
			'order.completed'      => array( __( 'Order completed', 'nodethemes-webhooks' ), 'order', false ),
			'order.cancelled'      => array( __( 'Order cancelled', 'nodethemes-webhooks' ), 'order', false ),
			'order.refunded'       => array( __( 'Order refunded (full or partial)', 'nodethemes-webhooks' ), 'order', true ),
			'customer.created'     => array( __( 'New customer account', 'nodethemes-webhooks' ), 'customer', true ),
			'product.low_stock'    => array( __( 'Product low in stock', 'nodethemes-webhooks' ), 'product', true ),
			'product.out_of_stock' => array( __( 'Product out of stock', 'nodethemes-webhooks' ), 'product', true ),
		);
		return (array) apply_filters( 'ntwh_events', $events );
	}

	/**
	 * Can this event be selected right now?
	 *
	 * @param string $event Event.
	 * @return bool
	 */
	public static function event_available( $event ) {
		$all = self::events();
		if ( ! isset( $all[ $event ] ) ) {
			return false;
		}
		return ! $all[ $event ][2] || NTWH_Pro::active();
	}

	/**
	 * Default endpoint fields.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'           => '',
			'name'         => '',
			'url'          => '',
			'secret'       => '',
			'events'       => array(),
			'active'       => true,
			'auth_name'    => '',
			'auth_value'   => '',
			// Pro fields (stored here, used only while Pro is active).
			'conditions'   => array(),
			'match'        => 'all',
			'payload_mode' => 'full',
			'template'     => '',
			'headers'      => array(),
			'created'      => '',
		);
	}

	/**
	 * All endpoints.
	 *
	 * @return array id => endpoint
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $ep ) {
			if ( is_array( $ep ) ) {
				$out[ $id ] = wp_parse_args( $ep, self::defaults() );
			}
		}
		return $out;
	}

	/**
	 * One endpoint.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Maximum number of endpoints.
	 *
	 * @return int
	 */
	public static function max() {
		return NTWH_Pro::active() ? PHP_INT_MAX : (int) apply_filters( 'ntwh_free_endpoints', NTWH_Pro::FREE_ENDPOINTS );
	}

	/**
	 * Does this endpoint use settings that only work with Pro?
	 *
	 * @param array $ep Endpoint.
	 * @return bool
	 */
	public static function uses_pro( $ep ) {
		if ( ! empty( $ep['conditions'] ) || 'custom' === $ep['payload_mode'] || ! empty( $ep['headers'] ) ) {
			return true;
		}
		$all = self::events();
		foreach ( (array) $ep['events'] as $e ) {
			if ( isset( $all[ $e ] ) && $all[ $e ][2] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is the endpoint paused because it needs Pro and Pro is not active?
	 * (Never send a filtered or custom endpoint without its filter: that could e.g. SMS every customer.)
	 *
	 * @param array $ep Endpoint.
	 * @return bool
	 */
	public static function paused_for_pro( $ep ) {
		return ! NTWH_Pro::active() && ( ! empty( $ep['conditions'] ) || 'custom' === $ep['payload_mode'] || ! empty( $ep['headers'] ) );
	}

	/**
	 * Endpoints subscribed to an event.
	 *
	 * @param string $event Event.
	 * @return array
	 */
	public static function for_event( $event ) {
		$out = array();
		foreach ( self::all() as $id => $ep ) {
			if ( $ep['active'] && in_array( $event, (array) $ep['events'], true ) ) {
				$out[ $id ] = $ep;
			}
		}
		return $out;
	}

	/**
	 * Validate the URL: http(s) only, no credentials in the URL.
	 *
	 * @param string $url URL.
	 * @return string Clean URL or ''.
	 */
	public static function clean_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) || ! empty( $p['user'] ) || ! empty( $p['pass'] ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Header name check (RFC 7230 token).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function clean_header_name( $name ) {
		$name = trim( (string) $name );
		return preg_match( '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/', $name ) ? $name : '';
	}

	/**
	 * Header value check (no line breaks).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function clean_header_value( $value ) {
		return substr( trim( str_replace( array( "\r", "\n", "\0" ), '', (string) $value ) ), 0, 2048 );
	}

	/**
	 * Save an endpoint from form input.
	 *
	 * @param array $raw Unslashed form data.
	 * @return string|WP_Error Endpoint ID.
	 */
	public static function save( $raw ) {
		$all = self::all();
		$id  = isset( $raw['id'] ) ? sanitize_key( $raw['id'] ) : '';
		$is_new = ( '' === $id || ! isset( $all[ $id ] ) );
		if ( $is_new && count( $all ) >= self::max() ) {
			/* translators: %d: number of endpoints */
			return new WP_Error( 'ntwh_limit', sprintf( __( 'The free version supports %d endpoints. Upgrade to Pro for unlimited endpoints.', 'nodethemes-webhooks' ), self::max() ) );
		}
		$old = $is_new ? self::defaults() : $all[ $id ];

		$url = self::clean_url( isset( $raw['url'] ) ? $raw['url'] : '' );
		if ( '' === $url ) {
			return new WP_Error( 'ntwh_url', __( 'Please enter a valid http:// or https:// URL (no user name or password inside the URL).', 'nodethemes-webhooks' ) );
		}

		$events = array();
		foreach ( (array) ( isset( $raw['events'] ) ? $raw['events'] : array() ) as $e ) {
			$e = sanitize_text_field( $e );
			if ( self::event_available( $e ) ) {
				$events[] = $e;
			}
		}
		// Keep Pro events that were selected earlier while Pro is off, so nothing is lost.
		if ( ! NTWH_Pro::active() ) {
			$catalogue = self::events();
			foreach ( (array) $old['events'] as $e ) {
				if ( isset( $catalogue[ $e ] ) && $catalogue[ $e ][2] && ! in_array( $e, $events, true ) ) {
					$events[] = $e;
				}
			}
		}
		if ( ! $events ) {
			return new WP_Error( 'ntwh_events', __( 'Choose at least one event.', 'nodethemes-webhooks' ) );
		}

		$secret = isset( $raw['secret'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw['secret'] ) : '';
		if ( strlen( $secret ) < 16 ) {
			$secret = '' !== $old['secret'] ? $old['secret'] : self::new_secret();
		}

		$ep = array_merge(
			$old,
			array(
				'id'         => $is_new ? 'ep_' . strtolower( wp_generate_password( 10, false, false ) ) : $id,
				'name'       => sanitize_text_field( isset( $raw['name'] ) ? $raw['name'] : '' ),
				'url'        => $url,
				'secret'     => $secret,
				'events'     => array_values( array_unique( $events ) ),
				'active'     => ! empty( $raw['active'] ),
				'auth_name'  => self::clean_header_name( isset( $raw['auth_name'] ) ? $raw['auth_name'] : '' ),
				'auth_value' => self::clean_header_value( isset( $raw['auth_value'] ) ? $raw['auth_value'] : '' ),
				'created'    => $is_new ? NTWH_DB::now() : $old['created'],
			)
		);
		if ( '' === $ep['auth_name'] ) {
			$ep['auth_value'] = '';
		}
		if ( '' === $ep['name'] ) {
			$host       = wp_parse_url( $url, PHP_URL_HOST );
			$ep['name'] = $host ? $host : __( 'Endpoint', 'nodethemes-webhooks' );
		}

		// The Pro add-on saves its own fields (conditions, payload template, headers).
		$ep = (array) apply_filters( 'ntwh_sanitize_endpoint', $ep, $raw, $old );

		$all[ $ep['id'] ] = $ep;
		update_option( self::OPTION, $all, false );
		return $ep['id'];
	}

	/**
	 * Delete.
	 *
	 * @param string $id ID.
	 */
	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		$h = self::health_all();
		unset( $h[ $id ] );
		update_option( self::HEALTH, $h, false );
	}

	/**
	 * Toggle on/off.
	 *
	 * @param string $id     ID.
	 * @param bool   $active Active.
	 */
	public static function set_active( $id, $active ) {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			$all[ $id ]['active'] = (bool) $active;
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * A new signing secret.
	 *
	 * @return string
	 */
	public static function new_secret() {
		return 'ntwhsec_' . wp_generate_password( 32, false, false );
	}

	/* ---------------------------------------------------------------------
	 * Health (per endpoint)
	 * ------------------------------------------------------------------ */

	/**
	 * All health records.
	 *
	 * @return array
	 */
	public static function health_all() {
		$h = get_option( self::HEALTH, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Health of one endpoint.
	 *
	 * @param string $id ID.
	 * @return array
	 */
	public static function health( $id ) {
		$h = self::health_all();
		return wp_parse_args(
			isset( $h[ $id ] ) && is_array( $h[ $id ] ) ? $h[ $id ] : array(),
			array(
				'last_success' => 0,
				'last_failure' => 0,
				'fails'        => 0,
				'last_error'   => '',
				'down_since'   => 0,
			)
		);
	}

	/**
	 * Record an attempt result.
	 *
	 * @param string $id    Endpoint ID.
	 * @param bool   $ok    Success?
	 * @param string $error Error message.
	 * @return array [ before, after ]
	 */
	public static function record( $id, $ok, $error = '' ) {
		$all    = self::health_all();
		$before = self::health( $id );
		$after  = $before;
		if ( $ok ) {
			$after['last_success'] = time();
			$after['fails']        = 0;
			$after['down_since']   = 0;
		} else {
			$after['last_failure'] = time();
			$after['fails']        = (int) $before['fails'] + 1;
			$after['last_error']   = substr( (string) $error, 0, 300 );
			if ( ! $before['down_since'] ) {
				$after['down_since'] = time();
			}
		}
		$all[ $id ] = $after;
		update_option( self::HEALTH, $all, false );
		return array( $before, $after );
	}
}

<?php
/**
 * Queue, delivery, signatures and retries.
 *
 * Flow: an event creates one "queued" row per endpoint -> at the end of the request the payload is
 * frozen (so the order is fully saved) -> a background job (Action Scheduler) sends it -> on failure
 * it is retried later; the row keeps the exact body, response and error for the log and for resends.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher.
 */
final class NTWH_Dispatcher {

	const HOOK  = 'ntwh_deliver';
	const GROUP = 'nodethemes-webhooks';

	/**
	 * Rows created in this request whose payload is frozen at shutdown.
	 *
	 * @var int[]
	 */
	private static $pending = array();

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'deliver' ), 10, 1 );
		add_action( 'ntwh_heal', array( __CLASS__, 'heal' ) );
		add_action( 'init', array( __CLASS__, 'ensure_heal' ) );
	}

	/**
	 * Free retry plan: seconds to wait before attempt 2, 3, ...
	 * Pro replaces it with a longer plan through the filter.
	 *
	 * @return int[]
	 */
	public static function retry_plan() {
		$plan = (array) apply_filters( 'ntwh_retry_plan', array( 60, 600 ) );
		return array_values( array_map( 'absint', $plan ) );
	}

	/**
	 * Queue an event for every subscribed endpoint.
	 *
	 * @param string $event       Event.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $context     Context.
	 * @param array  $only        Only these endpoint IDs (used by "send again"); empty = all subscribed.
	 * @param bool   $freeze_now  Build the payload right away (for objects that change again in the same request, e.g. stock).
	 * @return int[] Delivery IDs.
	 */
	public static function queue( $event, $object_type, $object_id, $context = array(), $only = array(), $freeze_now = false ) {
		$endpoints = NTWH_Endpoints::for_event( $event );
		if ( $only ) {
			$endpoints = array_intersect_key( NTWH_Endpoints::all(), array_flip( $only ) );
		}
		if ( ! $endpoints ) {
			return array();
		}
		$uuid   = wp_generate_uuid4();
		$ids    = array();
		$frozen = null;
		if ( $freeze_now ) {
			$env    = NTWH_Payload::envelope( $event, $object_type, $object_id, $context, $uuid );
			$frozen = null === $env ? null : NTWH_Payload::encode( $env );
		}
		foreach ( $endpoints as $ep ) {
			if ( NTWH_Endpoints::paused_for_pro( $ep ) ) {
				$ids[] = NTWH_DB::insert(
					array(
						'event_uuid'  => $uuid,
						'endpoint_id' => $ep['id'],
						'event'       => $event,
						'object_type' => $object_type,
						'object_id'   => (int) $object_id,
						'status'      => 'skipped',
						'context'     => NTWH_Payload::encode( $context ),
						'error'       => __( 'Not sent: this endpoint uses Pro settings (conditions, custom payload or headers) and NodeThemes Webhooks Pro is not active.', 'nodethemes-webhooks' ),
					)
				);
				continue;
			}
			$id = NTWH_DB::insert(
				array(
					'event_uuid'   => $uuid,
					'endpoint_id'  => $ep['id'],
					'event'        => $event,
					'object_type'  => $object_type,
					'object_id'    => (int) $object_id,
					'status'       => 'queued',
					'max_attempts' => count( self::retry_plan() ) + 1,
					'context'      => NTWH_Payload::encode( $context ),
					'payload'      => $frozen,
				)
			);
			if ( $id ) {
				$ids[]             = $id;
				self::$pending[]   = $id;
			}
		}
		if ( self::$pending && ! has_action( 'shutdown', array( __CLASS__, 'flush' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		}
		return $ids;
	}

	/**
	 * End of request: freeze payloads (one per event/object, shared by its endpoints) and schedule the sends.
	 */
	public static function flush() {
		$ids           = array_unique( self::$pending );
		self::$pending = array();
		$cache         = array();
		foreach ( $ids as $id ) {
			$row = NTWH_DB::get( $id );
			if ( ! $row || 'queued' !== $row->status ) {
				continue;
			}
			if ( null !== $row->payload && '' !== $row->payload ) {
				self::schedule( $id, 0 ); // Already frozen when the event happened.
				continue;
			}
			$key = $row->event_uuid;
			if ( ! isset( $cache[ $key ] ) ) {
				$env           = NTWH_Payload::envelope( $row->event, $row->object_type, (int) $row->object_id, (array) json_decode( (string) $row->context, true ), $row->event_uuid );
				$cache[ $key ] = null === $env ? null : NTWH_Payload::encode( $env );
			}
			if ( null === $cache[ $key ] ) {
				NTWH_DB::update(
					$id,
					array(
						'status' => 'skipped',
						'error'  => __( 'The order / object was deleted before it could be sent.', 'nodethemes-webhooks' ),
					)
				);
				continue;
			}
			NTWH_DB::update( $id, array( 'payload' => $cache[ $key ] ) );
			self::schedule( $id, 0 );
		}
	}

	/**
	 * Schedule a delivery job.
	 *
	 * @param int $id    Delivery ID.
	 * @param int $delay Seconds.
	 */
	public static function schedule( $id, $delay ) {
		$args = array( (int) $id );
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
			} else {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			}
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, $args );
	}

	/**
	 * Self-healing: every 10 minutes re-schedule deliveries whose job got lost
	 * (e.g. the plugin was deactivated while deliveries were waiting).
	 */
	public static function ensure_heal() {
		if ( ! wp_next_scheduled( 'ntwh_heal' ) ) {
			wp_schedule_event( time() + 600, 'ntwh_10min', 'ntwh_heal' );
		}
	}

	/**
	 * Re-schedule lost jobs.
	 */
	public static function heal() {
		foreach ( NTWH_DB::due_ids( 50 ) as $id ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( $id ), self::GROUP ) ) {
				continue;
			}
			$row = NTWH_DB::get( $id );
			// Touch so it is not picked again for 10 minutes; a send that died half-way is put back in the queue.
			NTWH_DB::update( $id, ( $row && 'sending' === $row->status ) ? array( 'status' => 'retrying' ) : array() );
			self::schedule( $id, 0 );
		}
	}

	/**
	 * Headers for a request.
	 *
	 * @param array  $ep   Endpoint.
	 * @param object $row  Delivery row.
	 * @param string $body Body.
	 * @return array
	 */
	public static function headers( $ep, $row, $body ) {
		$ts      = (string) time();
		$headers = array(
			'Content-Type'       => 'application/json; charset=utf-8',
			'User-Agent'         => 'NodeThemes-Webhooks/' . NTWH_VERSION . ' (+https://nodethemes.com/)',
			'X-NT-Event'         => $row->event,
			'X-NT-Event-Id'      => $row->event_uuid,
			'X-NT-Delivery-Id'   => (string) $row->id,
			'X-NT-Attempt'       => (string) ( (int) $row->attempts + 1 ),
			'X-NT-Timestamp'     => $ts,
			'X-NT-Signature'     => 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $body, (string) $ep['secret'] ),
			'X-NT-Site'          => home_url( '/' ),
		);
		if ( '' !== $ep['auth_name'] ) {
			$headers[ $ep['auth_name'] ] = $ep['auth_value'];
		}
		return (array) apply_filters( 'ntwh_request_headers', $headers, $ep, $row, $body );
	}

	/**
	 * Background job: send one delivery.
	 *
	 * @param int $id Delivery ID.
	 */
	public static function deliver( $id ) {
		if ( ! NTWH_DB::claim( (int) $id ) ) {
			return; // Already sent, being sent by another worker, or finished.
		}
		$row = NTWH_DB::get( (int) $id );
		if ( ! $row ) {
			return;
		}
		$ep = NTWH_Endpoints::get( $row->endpoint_id );
		if ( ! $ep ) {
			NTWH_DB::update( $id, array( 'status' => 'failed', 'error' => __( 'The endpoint was deleted.', 'nodethemes-webhooks' ) ) );
			return;
		}
		if ( ! $ep['active'] ) {
			NTWH_DB::update( $id, array( 'status' => 'skipped', 'error' => __( 'The endpoint is switched off.', 'nodethemes-webhooks' ) ) );
			return;
		}
		if ( NTWH_Endpoints::paused_for_pro( $ep ) ) {
			NTWH_DB::update( $id, array( 'status' => 'skipped', 'error' => __( 'Not sent: this endpoint uses Pro settings and NodeThemes Webhooks Pro is not active.', 'nodethemes-webhooks' ) ) );
			return;
		}

		// Payload missing (e.g. the request that created it ended early): build it now.
		if ( null === $row->payload || '' === $row->payload ) {
			$env = NTWH_Payload::envelope( $row->event, $row->object_type, (int) $row->object_id, (array) json_decode( (string) $row->context, true ), $row->event_uuid );
			if ( null === $env ) {
				NTWH_DB::update( $id, array( 'status' => 'skipped', 'error' => __( 'The order / object was deleted before it could be sent.', 'nodethemes-webhooks' ) ) );
				return;
			}
			$row->payload = NTWH_Payload::encode( $env );
			NTWH_DB::update( $id, array( 'payload' => $row->payload ) );
		}

		// Body is decided on the first attempt and then reused, so retries send exactly the same bytes.
		if ( null === $row->request_body || '' === $row->request_body ) {
			$envelope = json_decode( (string) $row->payload, true );
			// Conditions (Pro) decide whether this endpoint wants the event at all.
			if ( ! apply_filters( 'ntwh_endpoint_matches', true, $ep, $envelope, $row ) ) {
				NTWH_DB::update( $id, array( 'status' => 'skipped', 'error' => __( 'Skipped: the endpoint conditions did not match.', 'nodethemes-webhooks' ) ) );
				return;
			}
			$body = apply_filters( 'ntwh_request_body', null, $ep, $envelope, $row );
			if ( ! is_string( $body ) ) {
				$body = (string) $row->payload;
			}
			$row->request_body = $body;
			NTWH_DB::update( $id, array( 'request_body' => $body ) );
		}

		self::send( $row, $ep );
	}

	/**
	 * Do the HTTP request and record the result.
	 *
	 * @param object $row Delivery row (with request_body).
	 * @param array  $ep  Endpoint.
	 * @return bool Success.
	 */
	public static function send( $row, $ep ) {
		$body    = (string) $row->request_body;
		$start   = microtime( true );
		$res     = wp_remote_post(
			$ep['url'],
			array(
				'timeout'     => (int) apply_filters( 'ntwh_timeout', 15 ),
				'redirection' => 3,
				'headers'     => self::headers( $ep, $row, $body ),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );
		$attempts = (int) $row->attempts + 1;
		$code     = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$ok       = $code >= 200 && $code < 300;
		$error    = '';
		if ( is_wp_error( $res ) ) {
			$error = $res->get_error_message();
		} elseif ( ! $ok ) {
			/* translators: %d: HTTP status code */
			$error = sprintf( __( 'The endpoint answered with HTTP %d.', 'nodethemes-webhooks' ), $code );
		}
		$resp_body = is_wp_error( $res ) ? '' : substr( (string) wp_remote_retrieve_body( $res ), 0, 2000 );

		$data = array(
			'attempts'      => $attempts,
			'response_code' => $code,
			'response_body' => wp_check_invalid_utf8( $resp_body, true ),
			'error'         => $error,
			'duration_ms'   => $ms,
		);
		$plan  = self::retry_plan();
		$final = true;
		if ( $ok ) {
			$data['status']       = 'success';
			$data['delivered']    = NTWH_DB::now();
			$data['next_attempt'] = null;
		} elseif ( $attempts < (int) $row->max_attempts && isset( $plan[ $attempts - 1 ] ) && ! self::permanent( $code ) ) {
			$delay                = (int) $plan[ $attempts - 1 ];
			$data['status']       = 'retrying';
			$data['next_attempt'] = gmdate( 'Y-m-d H:i:s', time() + $delay );
			$final                = false;
		} else {
			$data['status']       = 'failed';
			$data['next_attempt'] = null;
		}
		NTWH_DB::update( $row->id, $data );
		if ( ! $final ) {
			self::schedule( (int) $row->id, (int) $plan[ $attempts - 1 ] );
		}

		list( $before, $after ) = NTWH_Endpoints::record( $ep['id'], $ok, $error );
		$row = NTWH_DB::get( $row->id );
		do_action( 'ntwh_delivery_result', $row, $ep, $ok, $final, $before, $after );
		return $ok;
	}

	/**
	 * Responses that will never succeed on retry. Only 410 Gone by default: n8n answers 404 while a
	 * workflow is inactive and proxies answer 401/403 during maintenance, and those must be retried.
	 *
	 * @param int $code HTTP code.
	 * @return bool
	 */
	private static function permanent( $code ) {
		return in_array( (int) $code, (array) apply_filters( 'ntwh_permanent_codes', array( 410 ) ), true );
	}

	/**
	 * Resend: a new delivery with the same event ID and payload (rebuilt body, so a fixed template is used).
	 *
	 * @param int  $id  Delivery to resend.
	 * @param bool $now Send right away (true) or in the background (false).
	 * @return int|WP_Error New delivery ID.
	 */
	public static function resend( $id, $now = true ) {
		$row = NTWH_DB::get( $id );
		if ( ! $row || 'inbound' === $row->object_type ) {
			return new WP_Error( 'ntwh_resend', __( 'This delivery cannot be resent.', 'nodethemes-webhooks' ) );
		}
		$ep = NTWH_Endpoints::get( $row->endpoint_id );
		if ( ! $ep ) {
			return new WP_Error( 'ntwh_resend', __( 'The endpoint of this delivery no longer exists.', 'nodethemes-webhooks' ) );
		}
		$new = NTWH_DB::insert(
			array(
				'event_uuid'   => $row->event_uuid,
				'endpoint_id'  => $row->endpoint_id,
				'event'        => $row->event,
				'object_type'  => $row->object_type,
				'object_id'    => (int) $row->object_id,
				'status'       => 'queued',
				'max_attempts' => count( self::retry_plan() ) + 1,
				'context'      => $row->context,
				'payload'      => $row->payload,
			)
		);
		if ( 'failed' === $row->status ) {
			/* translators: %d: delivery ID */
			NTWH_DB::update( $row->id, array( 'status' => 'resent', 'error' => trim( $row->error . ' ' . sprintf( __( 'Resent as delivery #%d.', 'nodethemes-webhooks' ), $new ) ) ) );
		}
		if ( $now ) {
			self::deliver( $new );
		} else {
			self::schedule( $new, 0 );
		}
		return $new;
	}

	/**
	 * "Send test" button: a synchronous ping, logged like any delivery.
	 *
	 * @param string $endpoint_id Endpoint.
	 * @return object|WP_Error Delivery row.
	 */
	public static function test( $endpoint_id ) {
		$ep = NTWH_Endpoints::get( $endpoint_id );
		if ( ! $ep ) {
			return new WP_Error( 'ntwh_test', __( 'Endpoint not found.', 'nodethemes-webhooks' ) );
		}
		$uuid = wp_generate_uuid4();
		$env  = NTWH_Payload::envelope( 'test.ping', 'test', 0, array( 'note' => 'Sent with the "Send test" button.' ), $uuid );
		$body = NTWH_Payload::encode( $env );
		$id   = NTWH_DB::insert(
			array(
				'event_uuid'   => $uuid,
				'endpoint_id'  => $ep['id'],
				'event'        => 'test.ping',
				'object_type'  => 'test',
				'object_id'    => 0,
				'status'       => 'queued',
				'max_attempts' => 1,
				'payload'      => $body,
				'request_body' => $body,
			)
		);
		self::send( NTWH_DB::get( $id ), $ep );
		return NTWH_DB::get( $id );
	}
}

// A 10-minute schedule for the self-healing check.
add_filter(
	'cron_schedules',
	function ( $s ) {
		if ( ! isset( $s['ntwh_10min'] ) ) {
			$s['ntwh_10min'] = array(
				'interval' => 600,
				'display'  => 'Every 10 minutes (NodeThemes Webhooks)',
			);
		}
		return $s;
	}
);

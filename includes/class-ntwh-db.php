<?php
/**
 * Delivery log / queue table.
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access for deliveries.
 */
final class NTWH_DB {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ntwh_deliveries';
	}

	/**
	 * Create / upgrade the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		$c = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid char(36) NOT NULL DEFAULT '',
			endpoint_id varchar(40) NOT NULL DEFAULT '',
			event varchar(60) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			max_attempts smallint(5) unsigned NOT NULL DEFAULT 3,
			context text NULL,
			payload longtext NULL,
			request_body longtext NULL,
			response_code smallint(5) unsigned NOT NULL DEFAULT 0,
			response_body text NULL,
			error text NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			next_attempt datetime NULL,
			delivered datetime NULL,
			PRIMARY KEY  (id),
			KEY endpoint_status (endpoint_id,status),
			KEY status (status),
			KEY object (object_type,object_id),
			KEY created (created)
		) {$c};"
		);
		update_option( 'ntwh_db_version', NTWH_DB_VERSION, false );
	}

	/**
	 * Current UTC time for the table.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Insert a delivery.
	 *
	 * @param array $row Columns.
	 * @return int ID.
	 */
	public static function insert( $row ) {
		global $wpdb;
		$now = self::now();
		$row = array_merge(
			array(
				'event_uuid' => wp_generate_uuid4(),
				'status'     => 'queued',
				'created'    => $now,
				'updated'    => $now,
			),
			$row
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a delivery.
	 *
	 * @param int   $id   ID.
	 * @param array $data Columns.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$data['updated'] = self::now();
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Take a waiting delivery for sending (atomic, so two workers never send the same row).
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function claim( $id ) {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'sending', updated = %s WHERE id = %d AND status IN ('queued','retrying')", self::now(), (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One delivery.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Search the log.
	 *
	 * @param array $args status, event, endpoint_id, object_id, object_type, page, per_page.
	 * @return array [ rows, total ]
	 */
	public static function query( $args ) {
		global $wpdb;
		$t     = self::table();
		$where = array( '1=1' );
		$vals  = array();
		foreach ( array( 'status', 'event', 'endpoint_id', 'object_type' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$where[] = "{$k} = %s";
				$vals[]  = (string) $args[ $k ];
			}
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[] = 'object_id = %d';
			$vals[]  = (int) $args['object_id'];
		}
		$per  = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 50;
		$page = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$w    = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$rows_sql  = "SELECT id, event_uuid, endpoint_id, event, object_type, object_id, status, attempts, max_attempts, context, response_code, error, duration_ms, created, updated, next_attempt, delivered FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ) );
		// phpcs:enable
		return array( (array) $rows, $total );
	}

	/**
	 * Counts per status over the last N hours.
	 *
	 * @param int $hours Hours.
	 * @return array status => count
	 */
	public static function status_counts( $hours = 24 ) {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM {$t} WHERE created >= %s GROUP BY status", gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Deliveries that are waiting (queued / retrying) but have no scheduled job any more,
	 * e.g. after the plugin was deactivated. Used by the self-healing check.
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public static function due_ids( $limit = 50 ) {
		global $wpdb;
		$t = self::table();
		return array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE ( ( status IN ('queued','retrying') AND ( next_attempt IS NULL OR next_attempt <= %s ) ) OR status = 'sending' ) AND updated <= %s ORDER BY id ASC LIMIT %d", self::now(), gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ), (int) $limit ) ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * Delete old rows (finished ones only; waiting deliveries are never deleted).
	 */
	public static function cleanup() {
		global $wpdb;
		$t    = self::table();
		$days = (int) apply_filters( 'ntwh_log_retention_days', 7 );
		$days = max( 1, min( 365, $days ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE status IN ('success','failed','skipped','resent') AND created < %s LIMIT 5000", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}
}

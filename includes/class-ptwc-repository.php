<?php
/**
 * Persistence for reconciliation findings.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores normalized evidence without customer or payment credentials.
 */
final class PTWC_Repository {
	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Set the site-specific table name.
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'ptwc_findings';
	}

	/**
	 * Insert or refresh a finding.
	 *
	 * Ignored findings stay ignored. Resolved findings reopen if the problem returns.
	 *
	 * @param array<string, mixed> $finding Normalized finding.
	 * @return array{id:int, fingerprint:string, should_notify:bool}
	 */
	public function upsert( array $finding ) {
		global $wpdb;

		$now         = current_time( 'mysql', true );
		$fingerprint = self::fingerprint( $finding );
		$existing    = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, finding_status FROM {$this->table_name} WHERE fingerprint = %s", $fingerprint ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$data = array(
			'order_id'           => absint( $finding['order_id'] ),
			'gateway'            => sanitize_key( $finding['gateway'] ),
			'provider_reference' => sanitize_text_field( $finding['provider_reference'] ),
			'type'               => sanitize_key( $finding['type'] ),
			'severity'           => self::sanitize_severity( $finding['severity'] ),
			'title'              => sanitize_text_field( $finding['title'] ),
			'message'            => sanitize_textarea_field( $finding['message'] ),
			'order_status'       => sanitize_key( $finding['order_status'] ),
			'provider_status'    => sanitize_key( $finding['provider_status'] ),
			'order_amount'       => sanitize_text_field( $finding['order_amount'] ),
			'provider_amount'    => sanitize_text_field( $finding['provider_amount'] ),
			'currency'           => strtoupper( sanitize_text_field( $finding['currency'] ) ),
			'details_json'       => wp_json_encode( isset( $finding['details'] ) ? $finding['details'] : array() ),
			'last_seen_gmt'      => $now,
			'resolved_at_gmt'    => null,
		);

		if ( $existing ) {
			$status                 = 'ignored' === $existing['finding_status'] ? 'ignored' : 'open';
			$data['finding_status'] = $status;

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->table_name,
				$data,
				array( 'id' => absint( $existing['id'] ) )
			);

			return array(
				'id'            => absint( $existing['id'] ),
				'fingerprint'   => $fingerprint,
				'should_notify' => 'resolved' === $existing['finding_status'],
			);
		}

		$data['fingerprint']    = $fingerprint;
		$data['finding_status'] = 'open';
		$data['first_seen_gmt'] = $now;

		$wpdb->insert( $this->table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'id'            => absint( $wpdb->insert_id ),
			'fingerprint'   => $fingerprint,
			'should_notify' => true,
		);
	}

	/**
	 * Resolve open findings of the given types that were not observed in this scan.
	 *
	 * @param int           $order_id Order ID.
	 * @param array<string> $present_fingerprints Findings observed now.
	 * @param array<string> $types Types the current scan could authoritatively check.
	 * @return int Number resolved.
	 */
	public function resolve_absent( $order_id, array $present_fingerprints, array $types ) {
		global $wpdb;

		if ( empty( $types ) ) {
			return 0;
		}

		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, fingerprint, type FROM {$this->table_name} WHERE order_id = %d AND finding_status = 'open'", absint( $order_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$resolved = 0;
		$now      = current_time( 'mysql', true );

		foreach ( $rows as $row ) {
			if ( ! in_array( $row['type'], $types, true ) || in_array( $row['fingerprint'], $present_fingerprints, true ) ) {
				continue;
			}

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->table_name,
				array(
					'finding_status'  => 'resolved',
					'resolved_at_gmt' => $now,
				),
				array( 'id' => absint( $row['id'] ) )
			);
			if ( false !== $updated ) {
				++$resolved;
			}
		}

		return $resolved;
	}

	/**
	 * Read findings for the admin screen.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items:array<int, object>, total:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => 'open',
				'severity' => '',
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$status = sanitize_key( $args['status'] );
		if ( ! in_array( $status, array( 'open', 'ignored', 'resolved' ), true ) ) {
			$status = '';
		}

		$severity = self::sanitize_severity( $args['severity'], '' );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		return array(
			'items' => $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM %i WHERE (%s = '' OR finding_status = %s) AND (%s = '' OR severity = %s) ORDER BY FIELD(severity, 'critical', 'warning', 'info'), last_seen_gmt DESC LIMIT %d OFFSET %d",
					$this->table_name,
					$status,
					$status,
					$severity,
					$severity,
					$per_page,
					$offset
				)
			),
			'total' => (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE (%s = '' OR finding_status = %s) AND (%s = '' OR severity = %s)",
					$this->table_name,
					$status,
					$status,
					$severity,
					$severity
				)
			),
		);
	}

	/**
	 * Count findings grouped by status and severity.
	 *
	 * @return array<string, int>
	 */
	public function summary() {
		global $wpdb;

		$summary = array(
			'open'          => 0,
			'open_critical' => 0,
			'open_warning'  => 0,
			'ignored'       => 0,
			'resolved'      => 0,
		);
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT finding_status, severity, COUNT(*) AS total FROM {$this->table_name} GROUP BY finding_status, severity", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$status = sanitize_key( $row['finding_status'] );
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ] += (int) $row['total'];
			}
			if ( 'open' === $status && isset( $summary[ 'open_' . $row['severity'] ] ) ) {
				$summary[ 'open_' . $row['severity'] ] += (int) $row['total'];
			}
		}

		return $summary;
	}

	/**
	 * Ignore or reopen one finding.
	 *
	 * @param int    $id Finding ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public function set_status( $id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'open', 'ignored' ), true ) ) {
			return false;
		}

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table_name,
			array(
				'finding_status'  => $status,
				'resolved_at_gmt' => null,
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Record that an alert was successfully delivered.
	 *
	 * @param int $id Finding ID.
	 * @return void
	 */
	public function mark_notified( $id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table_name,
			array( 'last_notified_gmt' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Produce a stable identity for one order/type/provider tuple.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return string
	 */
	public static function fingerprint( array $finding ) {
		return hash(
			'sha256',
			absint( $finding['order_id'] ) . '|' . sanitize_key( $finding['type'] ) . '|' . sanitize_text_field( $finding['provider_reference'] )
		);
	}

	/**
	 * Restrict severity values.
	 *
	 * @param string $severity Candidate value.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function sanitize_severity( $severity, $fallback = 'warning' ) {
		$severity = sanitize_key( $severity );
		return in_array( $severity, array( 'critical', 'warning', 'info' ), true ) ? $severity : $fallback;
	}
}

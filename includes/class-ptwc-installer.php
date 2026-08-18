<?php
/**
 * Database and lifecycle helpers.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates local storage and schedules read-only scans.
 */
final class PTWC_Installer {
	const SCAN_HOOK = 'ptwc_hourly_scan';

	/**
	 * Create storage and safe defaults.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();

		if ( false === get_option( 'ptwc_settings', false ) ) {
			add_option( 'ptwc_settings', self::default_settings(), '', false );
		}

		update_option( 'ptwc_db_version', PTWC_DB_VERSION, false );
		self::ensure_scan_scheduled();
	}

	/**
	 * Stop scheduled work without deleting merchant data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::SCAN_HOOK );
	}

	/**
	 * Apply schema updates after a plugin upgrade.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( PTWC_DB_VERSION !== (string) get_option( 'ptwc_db_version', '' ) ) {
			self::create_table();
			update_option( 'ptwc_db_version', PTWC_DB_VERSION, false );
		}
	}

	/**
	 * Ensure the hourly scan exists.
	 *
	 * @return void
	 */
	public static function ensure_scan_scheduled() {
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', self::SCAN_HOOK );
		}
	}

	/**
	 * Read settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$settings = get_option( 'ptwc_settings', array() );

		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::default_settings() );
	}

	/**
	 * Conservative defaults: scanning is enabled, outbound notifications are not.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings() {
		return array(
			'lookback_days'       => 7,
			'max_orders'          => 100,
			'stale_minutes'       => 30,
			'email_enabled'       => 0,
			'email_recipient'     => '',
			'webhook_channel'     => 'none',
			'webhook_url'         => '',
			'minimum_severity'    => 'warning',
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Create the findings table through WordPress's schema updater.
	 *
	 * @return void
	 */
	private static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'ptwc_findings';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fingerprint char(64) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			gateway varchar(64) NOT NULL DEFAULT '',
			provider_reference varchar(191) NOT NULL DEFAULT '',
			type varchar(64) NOT NULL,
			severity varchar(16) NOT NULL DEFAULT 'warning',
			finding_status varchar(16) NOT NULL DEFAULT 'open',
			title varchar(191) NOT NULL DEFAULT '',
			message text NOT NULL,
			order_status varchar(50) NOT NULL DEFAULT '',
			provider_status varchar(50) NOT NULL DEFAULT '',
			order_amount varchar(50) NOT NULL DEFAULT '',
			provider_amount varchar(50) NOT NULL DEFAULT '',
			currency varchar(10) NOT NULL DEFAULT '',
			details_json longtext NULL,
			first_seen_gmt datetime NOT NULL,
			last_seen_gmt datetime NOT NULL,
			resolved_at_gmt datetime NULL,
			last_notified_gmt datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY order_id (order_id),
			KEY finding_status (finding_status),
			KEY severity (severity),
			KEY last_seen_gmt (last_seen_gmt)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}

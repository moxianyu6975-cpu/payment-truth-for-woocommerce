<?php
/**
 * Plugin composition root.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's small set of services to WordPress hooks.
 */
final class PTWC_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var PTWC_Plugin|null
	 */
	private static $instance;

	/**
	 * Reconciliation scanner.
	 *
	 * @var PTWC_Scanner
	 */
	private $scanner;

	/**
	 * Admin controller.
	 *
	 * @var PTWC_Admin
	 */
	private $admin;

	/**
	 * Get the single plugin instance.
	 *
	 * @return PTWC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build collaborators.
	 */
	private function __construct() {
		$repository    = new PTWC_Repository();
		$reconciler    = new PTWC_Reconciler();
		$provider      = new PTWC_Stripe_Provider();
		$notifier      = new PTWC_Notifier();
		$this->scanner = new PTWC_Scanner( $repository, $reconciler, $provider, $notifier );
		$this->admin   = new PTWC_Admin( $repository, $this->scanner, $provider, $notifier );
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function run() {
		PTWC_Installer::maybe_upgrade();
		PTWC_Installer::ensure_scan_scheduled();

		add_action( PTWC_Installer::SCAN_HOOK, array( $this->scanner, 'run_scheduled' ) );
		$this->admin->register_hooks();
	}
}

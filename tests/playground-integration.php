<?php
/**
 * Runs inside a fresh WordPress Playground after all plugins activate.
 *
 * @package PaymentTruthForWooCommerce
 */

if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'PTWC_Plugin' ) ) {
	throw new RuntimeException( 'WooCommerce or Payment Truth did not load.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$old    = new WC_DateTime( '-2 hours', new DateTimeZone( 'UTC' ) );
$recent = new WC_DateTime( '-2 minutes', new DateTimeZone( 'UTC' ) );

$pending = wc_create_order();
$pending->set_status( 'pending' );
$pending->set_payment_method( 'stripe' );
$pending->set_currency( 'USD' );
$pending->set_total( 25 );
$pending->set_date_created( $old );
$pending->save();

$paid = wc_create_order();
$paid->set_status( 'processing' );
$paid->set_payment_method( 'stripe' );
$paid->set_currency( 'USD' );
$paid->set_total( 40 );
$paid->set_date_created( $old );
$paid->save();

$fresh = wc_create_order();
$fresh->set_status( 'pending' );
$fresh->set_payment_method( 'stripe' );
$fresh->set_currency( 'USD' );
$fresh->set_total( 15 );
$fresh->set_date_created( $recent );
$fresh->save();

do_action( PTWC_Installer::SCAN_HOOK );
$first   = get_option( PTWC_Scanner::LAST_SCAN_KEY, array() );
$summary = ( new PTWC_Repository() )->summary();

$assert( 3 === (int) $first['orders_scanned'], 'Expected three Stripe orders in the first scan.' );
$assert( 3 === (int) $first['findings_new'], 'Expected three new findings in the first scan.' );
$assert( 0 === (int) $first['provider_reads'], 'Orders without references must not contact Stripe.' );
$assert( empty( $first['errors'] ), 'First scan reported unexpected errors.' );
$assert( 3 === $summary['open'], 'Expected three open findings after first scan.' );
$assert( 1 === $summary['open_critical'], 'Expected one critical finding after first scan.' );
$assert( 2 === $summary['open_warning'], 'Expected two warnings after first scan.' );

$pending->set_status( 'cancelled' );
$pending->save();

do_action( PTWC_Installer::SCAN_HOOK );
$second  = get_option( PTWC_Scanner::LAST_SCAN_KEY, array() );
$summary = ( new PTWC_Repository() )->summary();

$assert( 0 === (int) $second['findings_new'], 'Second scan must deduplicate existing findings.' );
$assert( 1 === (int) $second['findings_resolved'], 'Stale-pending finding should auto-resolve.' );
$assert( 2 === $summary['open'], 'Expected two remaining open missing-reference findings.' );
$assert( 1 === $summary['resolved'], 'Expected one resolved finding.' );
$assert( false !== wp_next_scheduled( PTWC_Installer::SCAN_HOOK ), 'Hourly scan was not scheduled.' );

$settings = PTWC_Installer::get_settings();
$assert( empty( $settings['email_enabled'] ), 'Email must be disabled by default.' );
$assert( 'none' === $settings['webhook_channel'], 'Webhook must be disabled by default.' );

echo wp_json_encode(
	array(
		'result'            => 'pass',
		'orders'            => array( $pending->get_id(), $paid->get_id(), $fresh->get_id() ),
		'first_scan'        => $first,
		'second_scan'       => $second,
		'findings_summary'  => $summary,
		'stripe_api_loaded' => class_exists( 'WC_Stripe_API' ),
	)
);

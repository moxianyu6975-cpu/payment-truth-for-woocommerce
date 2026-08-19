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

$provider_responses = array(
	'pi_provider_paid' => array(
		'id'              => 'pi_provider_paid',
		'status'          => 'succeeded',
		'amount'          => 1000,
		'amount_received' => 1000,
		'currency'        => 'usd',
		'latest_charge'   => array( 'amount_refunded' => 0 ),
	),
	'pi_provider_failed' => array(
		'id'            => 'pi_provider_failed',
		'status'        => 'canceled',
		'amount'        => 2000,
		'currency'      => 'usd',
		'latest_charge' => array( 'amount_refunded' => 0 ),
	),
	'pi_value_mismatch' => array(
		'id'              => 'pi_value_mismatch',
		'status'          => 'succeeded',
		'amount'          => 2900,
		'amount_received' => 2900,
		'currency'        => 'eur',
		'latest_charge'   => array( 'amount_refunded' => 100 ),
	),
	'ch_clean' => array(
		'id'              => 'ch_clean',
		'paid'            => true,
		'captured'        => true,
		'failure_code'    => null,
		'amount'          => 5000,
		'amount_refunded' => 0,
		'currency'        => 'usd',
	),
);
$provider_requests  = array();

WC_Stripe_API::set_secret_key( 'sk_test_payment_truth' );
add_filter(
	'pre_http_request',
	static function ( $preempt, $request, $url ) use ( &$provider_responses, &$provider_requests ) {
		if ( 0 !== strpos( $url, WC_Stripe_API::ENDPOINT ) ) {
			return $preempt;
		}

		$provider_requests[] = $url;
		if ( ! preg_match( '/\/(pi|ch)_[A-Za-z0-9_]+/', $url, $matches ) ) {
			return new WP_Error( 'unexpected_stripe_request', 'Unexpected Stripe test request.' );
		}

		$reference = ltrim( $matches[0], '/' );
		if ( ! isset( $provider_responses[ $reference ] ) ) {
			return new WP_Error( 'missing_stripe_fixture', 'Missing Stripe test fixture.' );
		}

		return array(
			'headers'  => array( 'request-id' => 'req_payment_truth_test' ),
			'body'     => wp_json_encode( $provider_responses[ $reference ] ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);

$create_referenced_order = static function ( $status, $total, $reference, $created ) {
	$order = wc_create_order();
	$order->set_status( $status );
	$order->set_payment_method( 'stripe' );
	$order->set_currency( 'USD' );
	$order->set_total( $total );
	$order->set_date_created( $created );
	$order->update_meta_data( '_stripe_intent_id', $reference );
	$order->save();
	return $order;
};

$provider_paid = $create_referenced_order( 'pending', 10, 'pi_provider_paid', $old );
$provider_failed = $create_referenced_order( 'processing', 20, 'pi_provider_failed', $old );
$value_mismatch = $create_referenced_order( 'processing', 30, 'pi_value_mismatch', $old );
$clean_charge = $create_referenced_order( 'processing', 50, 'ch_clean', $old );

do_action( PTWC_Installer::SCAN_HOOK );
$third   = get_option( PTWC_Scanner::LAST_SCAN_KEY, array() );
$summary = ( new PTWC_Repository() )->summary();
$all     = ( new PTWC_Repository() )->query(
	array(
		'status'   => 'all',
		'severity' => '',
		'page'     => 1,
		'per_page' => 50,
	)
);
$types   = array_map(
	static function ( $item ) {
		return $item->type;
	},
	$all['items']
);

$assert( 7 === (int) $third['orders_scanned'], 'Expected seven Stripe orders in the provider scan.' );
$assert( 4 === (int) $third['provider_reads'], 'Expected four provider reads through the official Stripe gateway API.' );
$assert( 6 === (int) $third['findings_new'], 'Expected six provider-backed findings.' );
$assert( empty( $third['errors'] ), 'Provider-backed scan reported unexpected errors.' );
$assert( 8 === $summary['open'], 'Expected eight open findings after provider reconciliation.' );
$assert( in_array( PTWC_Reconciler::TYPE_PROVIDER_PAID_ORDER_UNPAID, $types, true ), 'Missing provider-paid/order-unpaid finding.' );
$assert( in_array( PTWC_Reconciler::TYPE_ORDER_PAID_PROVIDER_UNPAID, $types, true ), 'Missing order-paid/provider-failed finding.' );
$assert( in_array( PTWC_Reconciler::TYPE_AMOUNT_MISMATCH, $types, true ), 'Missing amount mismatch finding.' );
$assert( in_array( PTWC_Reconciler::TYPE_CURRENCY_MISMATCH, $types, true ), 'Missing currency mismatch finding.' );
$assert( in_array( PTWC_Reconciler::TYPE_REFUND_MISMATCH, $types, true ), 'Missing refund mismatch finding.' );
$assert( 4 === count( $provider_requests ), 'Expected one intercepted Stripe request per referenced order.' );

$provider_paid->set_status( 'processing' );
$provider_paid->save();
$provider_responses['pi_provider_failed'] = array(
	'id'              => 'pi_provider_failed',
	'status'          => 'succeeded',
	'amount'          => 2000,
	'amount_received' => 2000,
	'currency'        => 'usd',
	'latest_charge'   => array( 'amount_refunded' => 0 ),
);
$provider_responses['pi_value_mismatch'] = array(
	'id'              => 'pi_value_mismatch',
	'status'          => 'succeeded',
	'amount'          => 3000,
	'amount_received' => 3000,
	'currency'        => 'usd',
	'latest_charge'   => array( 'amount_refunded' => 0 ),
);

do_action( PTWC_Installer::SCAN_HOOK );
$fourth  = get_option( PTWC_Scanner::LAST_SCAN_KEY, array() );
$summary = ( new PTWC_Repository() )->summary();

$assert( 4 === (int) $fourth['provider_reads'], 'Expected four provider reads during resolution scan.' );
$assert( 0 === (int) $fourth['findings_new'], 'Resolution scan must not create new findings.' );
$assert( 6 === (int) $fourth['findings_resolved'], 'Expected all six provider-backed findings to resolve.' );
$assert( 2 === $summary['open'], 'Only the two missing-reference findings should remain open.' );
$assert( 7 === $summary['resolved'], 'Expected seven resolved findings in total.' );

$settings = PTWC_Installer::get_settings();
$assert( empty( $settings['email_enabled'] ), 'Email must be disabled by default.' );
$assert( 'none' === $settings['webhook_channel'], 'Webhook must be disabled by default.' );

echo wp_json_encode(
	array(
		'result'            => 'pass',
		'orders'            => array( $pending->get_id(), $paid->get_id(), $fresh->get_id(), $provider_paid->get_id(), $provider_failed->get_id(), $value_mismatch->get_id(), $clean_charge->get_id() ),
		'first_scan'        => $first,
		'second_scan'       => $second,
		'provider_scan'     => $third,
		'resolution_scan'   => $fourth,
		'findings_summary'  => $summary,
		'provider_requests' => count( $provider_requests ),
		'stripe_api_loaded' => class_exists( 'WC_Stripe_API' ),
	)
);

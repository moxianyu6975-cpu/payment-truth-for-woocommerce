<?php
/**
 * Dependency-free core regression tests.
 *
 * Run: php tests/test-core.php
 *
 * @package PaymentTruthForWooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

/** Minimal translation stub. */
function __( $text ) {
	return $text;
}

/** Minimal key sanitizer stub. */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/** Minimal text sanitizer stub. */
function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

/** URL validator stub. */
function wp_http_validate_url( $url ) {
	return false !== filter_var( $url, FILTER_VALIDATE_URL );
}

/** URL parser stub. */
function wp_parse_url( $url ) {
	return parse_url( $url );
}

/**
 * Stub order containing only methods used by the Stripe adapter.
 */
final class PTWC_Test_Order {
	/** @var string */
	private $method;

	/** @var string */
	private $meta;

	/** @var string */
	private $transaction;

	/**
	 * Build a fake order.
	 *
	 * @param string $method Gateway method.
	 * @param string $meta Intent meta.
	 * @param string $transaction Transaction ID.
	 */
	public function __construct( $method = 'stripe', $meta = '', $transaction = '' ) {
		$this->method      = $method;
		$this->meta        = $meta;
		$this->transaction = $transaction;
	}

	/** Get payment method. */
	public function get_payment_method() {
		return $this->method;
	}

	/** Get order meta. */
	public function get_meta() {
		return $this->meta;
	}

	/** Get transaction ID. */
	public function get_transaction_id() {
		return $this->transaction;
	}
}

/**
 * Stub official gateway API.
 */
final class WC_Stripe_API {
	/** @var object|null */
	public static $response;

	/** @var string */
	public static $last_endpoint = '';

	/** Return the prepared fake response. */
	public static function retrieve( $endpoint ) {
		self::$last_endpoint = $endpoint;
		return self::$response;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ptwc-reconciler.php';
require_once dirname( __DIR__ ) . '/includes/class-ptwc-stripe-provider.php';
require_once dirname( __DIR__ ) . '/includes/class-ptwc-notifier.php';

$tests = 0;

/**
 * Assert a condition and stop on failure.
 *
 * @param bool   $condition Condition.
 * @param string $message Failure message.
 * @return void
 */
function ptwc_assert( $condition, $message ) {
	global $tests;
	++$tests;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

/** Extract finding types. */
function ptwc_types( array $findings ) {
	return array_column( $findings, 'type' );
}

$reconciler = new PTWC_Reconciler();
$order      = array(
	'id'             => 7,
	'number'         => '1007',
	'status'         => 'pending',
	'is_paid'        => false,
	'amount_minor'   => '1000',
	'refunded_minor' => '0',
	'currency'       => 'USD',
	'age_seconds'    => 600,
	'payment_method' => 'stripe',
);
$provider   = array(
	'reference'               => 'pi_test123',
	'status'                  => 'succeeded',
	'amount_minor'            => '1000',
	'refunded_minor'          => '0',
	'currency'                => 'USD',
	'is_paid'                 => true,
	'is_terminal_failure'     => false,
	'is_amount_authoritative' => true,
	'is_refund_authoritative' => true,
);

$young_order                = $order;
$young_order['age_seconds'] = 299;
ptwc_assert( array() === $reconciler->compare( $young_order, $provider, 300 ), 'Grace period must suppress an otherwise matching status alert.' );

$types = ptwc_types( $reconciler->compare( $order, $provider, 300 ) );
ptwc_assert( in_array( PTWC_Reconciler::TYPE_PROVIDER_PAID_ORDER_UNPAID, $types, true ), 'Provider-paid/Woo-unpaid mismatch must be detected.' );

$paid_order             = $order;
$paid_order['is_paid']  = true;
$failed_provider        = $provider;
$failed_provider['is_paid']             = false;
$failed_provider['is_terminal_failure'] = true;
$failed_provider['status']              = 'canceled';
$types = ptwc_types( $reconciler->compare( $paid_order, $failed_provider ) );
ptwc_assert( in_array( PTWC_Reconciler::TYPE_ORDER_PAID_PROVIDER_UNPAID, $types, true ), 'Woo-paid/provider-failed mismatch must be detected.' );

$wrong_values                 = $provider;
$wrong_values['amount_minor'] = '990';
$wrong_values['currency']     = 'EUR';
$types = ptwc_types( $reconciler->compare( $paid_order, $wrong_values ) );
ptwc_assert( in_array( PTWC_Reconciler::TYPE_AMOUNT_MISMATCH, $types, true ), 'Amount mismatch must be detected.' );
ptwc_assert( in_array( PTWC_Reconciler::TYPE_CURRENCY_MISMATCH, $types, true ), 'Currency mismatch must be detected.' );

$refund_order                     = $paid_order;
$refund_order['refunded_minor']   = '250';
$refund_provider                  = $provider;
$refund_provider['refunded_minor'] = '200';
$types = ptwc_types( $reconciler->compare( $refund_order, $refund_provider ) );
ptwc_assert( in_array( PTWC_Reconciler::TYPE_REFUND_MISMATCH, $types, true ), 'Refund mismatch must be detected.' );

$refund_provider['is_refund_authoritative'] = false;
$types = ptwc_types( $reconciler->compare( $refund_order, $refund_provider ) );
ptwc_assert( ! in_array( PTWC_Reconciler::TYPE_REFUND_MISMATCH, $types, true ), 'Unknown provider refund state must not create a finding.' );

ptwc_assert( 1 === count( $reconciler->local_findings( $order, 300 ) ), 'Old pending order must create one warning.' );
$not_stale           = $order;
$not_stale['status'] = 'failed';
ptwc_assert( array() === $reconciler->local_findings( $not_stale, 300 ), 'Non-pending order must not create a stale warning.' );

$missing = $reconciler->missing_reference_finding( $paid_order );
ptwc_assert( 'critical' === $missing['severity'], 'Paid order without a reference must be critical.' );

$adapter = new PTWC_Stripe_Provider();
ptwc_assert( $adapter->supports_order( new PTWC_Test_Order( 'stripe_sepa' ) ), 'Official Stripe-family method must be supported.' );
ptwc_assert( ! $adapter->supports_order( new PTWC_Test_Order( 'paypal' ) ), 'Unrelated gateway must not be supported.' );
ptwc_assert( 'pi_meta123' === $adapter->get_reference( new PTWC_Test_Order( 'stripe', 'pi_meta123', 'ch_fallback' ) ), 'Intent meta must take precedence.' );
ptwc_assert( '' === $adapter->get_reference( new PTWC_Test_Order( 'stripe', 'not-an-id', '' ) ), 'Unrecognized references must be rejected.' );

WC_Stripe_API::$response = (object) array(
	'id'              => 'pi_meta123',
	'status'          => 'succeeded',
	'amount'          => 1000,
	'amount_received' => 1000,
	'currency'        => 'usd',
	'latest_charge'   => (object) array( 'amount_refunded' => 250 ),
);
$result = $adapter->fetch( new PTWC_Test_Order( 'stripe', 'pi_meta123', '' ) );
ptwc_assert( true === $result['ok'] && true === $result['snapshot']['is_paid'], 'Successful PaymentIntent must normalize as paid.' );
ptwc_assert( '250' === $result['snapshot']['refunded_minor'], 'Expanded charge refund amount must be normalized.' );
ptwc_assert( false !== strpos( WC_Stripe_API::$last_endpoint, 'expand[]=latest_charge' ), 'PaymentIntent request must expand the latest charge.' );

WC_Stripe_API::$response = (object) array(
	'id'              => 'ch_test123',
	'paid'            => false,
	'captured'        => false,
	'failure_code'    => 'card_declined',
	'amount'          => 1000,
	'amount_refunded' => 0,
	'currency'        => 'usd',
);
$result = $adapter->fetch( new PTWC_Test_Order( 'stripe', '', 'ch_test123' ) );
ptwc_assert( true === $result['snapshot']['is_terminal_failure'], 'Failed charge must normalize as terminal failure.' );

ptwc_assert( PTWC_Notifier::validate_webhook_url( 'https://open.feishu.cn/open-apis/bot/v2/hook/token', 'feishu' ), 'Official Feishu HTTPS webhook must be accepted.' );
ptwc_assert( ! PTWC_Notifier::validate_webhook_url( 'https://open.feishu.cn.evil.example/hook', 'feishu' ), 'Lookalike webhook host must be rejected.' );
ptwc_assert( ! PTWC_Notifier::validate_webhook_url( 'http://open.feishu.cn/hook', 'feishu' ), 'Non-HTTPS webhook must be rejected.' );
ptwc_assert( ! PTWC_Notifier::validate_webhook_url( 'https://oapi.dingtalk.com/robot/send', 'wecom' ), 'Webhook host must match selected channel.' );
ptwc_assert( ! PTWC_Notifier::validate_webhook_url( 'https://user:pass@qyapi.weixin.qq.com/cgi-bin/webhook/send', 'wecom' ), 'Credential-bearing webhook URL must be rejected.' );

fwrite( STDOUT, "PASS: {$tests} core assertions\n" );

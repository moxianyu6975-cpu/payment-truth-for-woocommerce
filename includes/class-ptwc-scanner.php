<?php
/**
 * Bounded reconciliation scanner.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates order reads, provider reads, rules, storage, and alerts.
 */
final class PTWC_Scanner {
	const LOCK_KEY       = 'ptwc_scan_lock';
	const LAST_SCAN_KEY  = 'ptwc_last_scan';
	const PROVIDER_TYPES = array(
		PTWC_Reconciler::TYPE_PROVIDER_PAID_ORDER_UNPAID,
		PTWC_Reconciler::TYPE_ORDER_PAID_PROVIDER_UNPAID,
		PTWC_Reconciler::TYPE_AMOUNT_MISMATCH,
		PTWC_Reconciler::TYPE_CURRENCY_MISMATCH,
		PTWC_Reconciler::TYPE_REFUND_MISMATCH,
	);

	/**
	 * Finding persistence.
	 *
	 * @var PTWC_Repository
	 */
	private $repository;

	/**
	 * Pure comparison rules.
	 *
	 * @var PTWC_Reconciler
	 */
	private $reconciler;

	/**
	 * Stripe adapter.
	 *
	 * @var PTWC_Stripe_Provider
	 */
	private $provider;

	/**
	 * Alert delivery.
	 *
	 * @var PTWC_Notifier
	 */
	private $notifier;

	/**
	 * Wire scanner collaborators.
	 *
	 * @param PTWC_Repository      $repository Repository.
	 * @param PTWC_Reconciler      $reconciler Rules.
	 * @param PTWC_Stripe_Provider $provider Provider.
	 * @param PTWC_Notifier        $notifier Notifier.
	 */
	public function __construct( $repository, $reconciler, $provider, $notifier ) {
		$this->repository = $repository;
		$this->reconciler = $reconciler;
		$this->provider   = $provider;
		$this->notifier   = $notifier;
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public function run_scheduled() {
		$this->scan_recent();
	}

	/**
	 * Scan a bounded recent order window.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function scan_recent() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return new WP_Error( 'ptwc_scan_locked', __( 'A Payment Truth scan is already running.', 'payment-truth-for-woocommerce' ) );
		}

		set_transient( self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );
		$started  = time();
		$settings = PTWC_Installer::get_settings();
		$report   = array(
			'started_gmt'       => gmdate( 'Y-m-d H:i:s', $started ),
			'finished_gmt'      => '',
			'orders_scanned'    => 0,
			'provider_reads'    => 0,
			'findings_seen'     => 0,
			'findings_new'      => 0,
			'findings_resolved' => 0,
			'notifications'     => 0,
			'errors'            => array(),
		);

		try {
			$orders = wc_get_orders(
				array(
					'limit'        => min( 500, max( 1, absint( $settings['max_orders'] ) ) ),
					'orderby'      => 'date',
					'order'        => 'DESC',
					'date_created' => '>=' . ( time() - ( min( 90, max( 1, absint( $settings['lookback_days'] ) ) ) * DAY_IN_SECONDS ) ),
					'return'       => 'objects',
				)
			);

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order || ! $this->provider->supports_order( $order ) ) {
					continue;
				}

				++$report['orders_scanned'];
				$this->scan_order( $order, $settings, $report );
			}
		} catch ( Throwable $exception ) {
			$report['errors'][] = 'scan_failed';
		}

		$report['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$report['duration']     = max( 0, time() - $started );
		update_option( self::LAST_SCAN_KEY, $report, false );
		delete_transient( self::LOCK_KEY );

		return $report;
	}

	/**
	 * Scan one Stripe order.
	 *
	 * @param WC_Order            $order WooCommerce order.
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $report Mutable report.
	 * @return void
	 */
	private function scan_order( $order, array $settings, array &$report ) {
		$snapshot           = $this->order_snapshot( $order );
		$local_findings     = $this->reconciler->local_findings( $snapshot, absint( $settings['stale_minutes'] ) * MINUTE_IN_SECONDS );
		$local_fingerprints = $this->store_findings( $local_findings, $order, $report );

		$report['findings_resolved'] += $this->repository->resolve_absent(
			$order->get_id(),
			$local_fingerprints,
			array( PTWC_Reconciler::TYPE_STALE_PENDING )
		);

		$reference = $this->provider->get_reference( $order );
		if ( ! $reference ) {
			$missing_fingerprints = array();
			if ( $snapshot['age_seconds'] >= 300 ) {
				$missing_fingerprints = $this->store_findings(
					array( $this->reconciler->missing_reference_finding( $snapshot ) ),
					$order,
					$report
				);
			}

			$report['findings_resolved'] += $this->repository->resolve_absent(
				$order->get_id(),
				$missing_fingerprints,
				array( PTWC_Reconciler::TYPE_MISSING_REFERENCE )
			);
			return;
		}

		$report['findings_resolved'] += $this->repository->resolve_absent(
			$order->get_id(),
			array(),
			array( PTWC_Reconciler::TYPE_MISSING_REFERENCE )
		);

		++$report['provider_reads'];
		$result = $this->provider->fetch( $order );
		if ( empty( $result['ok'] ) ) {
			$error                      = isset( $result['error'] ) ? sanitize_key( $result['error'] ) : 'provider_read_failed';
			$report['errors'][ $error ] = isset( $report['errors'][ $error ] ) ? $report['errors'][ $error ] + 1 : 1;
			return;
		}

		$provider_findings            = $this->reconciler->compare( $snapshot, $result['snapshot'], 300 );
		$provider_fingerprints        = $this->store_findings( $provider_findings, $order, $report );
		$report['findings_resolved'] += $this->repository->resolve_absent(
			$order->get_id(),
			$provider_fingerprints,
			self::PROVIDER_TYPES
		);
	}

	/**
	 * Persist findings and notify only when newly opened.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings.
	 * @param WC_Order                       $order Order.
	 * @param array<string,mixed>            $report Mutable report.
	 * @return array<string>
	 */
	private function store_findings( array $findings, $order, array &$report ) {
		$fingerprints = array();

		foreach ( $findings as $finding ) {
			$result         = $this->repository->upsert( $finding );
			$fingerprints[] = $result['fingerprint'];
			++$report['findings_seen'];

			if ( $result['should_notify'] ) {
				++$report['findings_new'];
				if ( $this->notifier->notify( $finding, $order ) ) {
					$this->repository->mark_notified( $result['id'] );
					++$report['notifications'];
				}
			}
		}

		return $fingerprints;
	}

	/**
	 * Normalize order values to match Stripe's smallest currency unit.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function order_snapshot( $order ) {
		$date = $order->get_date_created();

		return array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'is_paid'        => $order->is_paid() || (bool) $order->get_date_paid() || 'refunded' === $order->get_status(),
			'amount_minor'   => $this->to_minor_units( $order->get_total(), $order->get_currency() ),
			'refunded_minor' => $this->to_minor_units( $order->get_total_refunded(), $order->get_currency() ),
			'currency'       => strtoupper( $order->get_currency() ),
			'age_seconds'    => $date ? max( 0, time() - $date->getTimestamp() ) : 0,
			'payment_method' => $order->get_payment_method(),
		);
	}

	/**
	 * Convert a decimal amount using the official gateway helper when available.
	 *
	 * @param string|float $amount Amount.
	 * @param string       $currency Currency.
	 * @return string
	 */
	private function to_minor_units( $amount, $currency ) {
		if ( class_exists( 'WC_Stripe_Helper' ) && method_exists( 'WC_Stripe_Helper', 'get_stripe_amount' ) ) {
			return (string) WC_Stripe_Helper::get_stripe_amount( $amount, $currency );
		}

		$zero_decimal  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$three_decimal = array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );
		$currency      = strtoupper( $currency );
		$decimals      = in_array( $currency, $zero_decimal, true ) ? 0 : ( in_array( $currency, $three_decimal, true ) ? 3 : 2 );

		return (string) round( (float) $amount * ( 10 ** $decimals ) );
	}
}

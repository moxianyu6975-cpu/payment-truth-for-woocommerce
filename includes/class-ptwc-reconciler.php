<?php
/**
 * Pure payment reconciliation rules.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compares normalized order and provider snapshots without changing either system.
 */
final class PTWC_Reconciler {
	const TYPE_PROVIDER_PAID_ORDER_UNPAID = 'provider_paid_order_unpaid';
	const TYPE_ORDER_PAID_PROVIDER_UNPAID = 'order_paid_provider_unpaid';
	const TYPE_AMOUNT_MISMATCH            = 'amount_mismatch';
	const TYPE_CURRENCY_MISMATCH          = 'currency_mismatch';
	const TYPE_REFUND_MISMATCH            = 'refund_mismatch';
	const TYPE_STALE_PENDING              = 'stale_pending';
	const TYPE_MISSING_REFERENCE          = 'missing_provider_reference';

	/**
	 * Compare two normalized snapshots.
	 *
	 * @param array<string, mixed> $order Order snapshot.
	 * @param array<string, mixed> $provider Provider snapshot.
	 * @param int                  $grace_seconds Grace period for asynchronous updates.
	 * @return array<int, array<string, mixed>>
	 */
	public function compare( array $order, array $provider, $grace_seconds = 300 ) {
		$findings          = array();
		$order_paid        = ! empty( $order['is_paid'] );
		$provider_paid     = ! empty( $provider['is_paid'] );
		$provider_terminal = ! empty( $provider['is_terminal_failure'] );
		$old_enough        = (int) $order['age_seconds'] >= max( 0, (int) $grace_seconds );

		if ( $old_enough && $provider_paid && ! $order_paid ) {
			$findings[] = $this->finding(
				self::TYPE_PROVIDER_PAID_ORDER_UNPAID,
				'critical',
				__( 'Payment succeeded but the order is not paid', 'payment-truth-for-woocommerce' ),
				__( 'The payment provider reports a successful payment while WooCommerce still treats the order as unpaid.', 'payment-truth-for-woocommerce' ),
				$order,
				$provider
			);
		}

		if ( $old_enough && $order_paid && $provider_terminal ) {
			$findings[] = $this->finding(
				self::TYPE_ORDER_PAID_PROVIDER_UNPAID,
				'critical',
				__( 'WooCommerce says paid but the provider does not', 'payment-truth-for-woocommerce' ),
				__( 'The order is marked paid in WooCommerce, but the payment provider reports a failed or cancelled payment.', 'payment-truth-for-woocommerce' ),
				$order,
				$provider
			);
		}

		if (
			! empty( $provider['is_amount_authoritative'] ) &&
			(string) $order['amount_minor'] !== (string) $provider['amount_minor']
		) {
			$findings[] = $this->finding(
				self::TYPE_AMOUNT_MISMATCH,
				'critical',
				__( 'Order and provider amounts do not match', 'payment-truth-for-woocommerce' ),
				__( 'The amount stored by WooCommerce differs from the amount authorized or collected by the payment provider.', 'payment-truth-for-woocommerce' ),
				$order,
				$provider
			);
		}

		if (
			! empty( $provider['currency'] ) &&
			strtoupper( (string) $order['currency'] ) !== strtoupper( (string) $provider['currency'] )
		) {
			$findings[] = $this->finding(
				self::TYPE_CURRENCY_MISMATCH,
				'critical',
				__( 'Order and provider currencies do not match', 'payment-truth-for-woocommerce' ),
				__( 'The currency stored by WooCommerce differs from the payment provider currency.', 'payment-truth-for-woocommerce' ),
				$order,
				$provider
			);
		}

		if (
			! empty( $provider['is_refund_authoritative'] ) &&
			(string) ( isset( $order['refunded_minor'] ) ? $order['refunded_minor'] : '0' ) !== (string) ( isset( $provider['refunded_minor'] ) ? $provider['refunded_minor'] : '0' )
		) {
			$findings[] = $this->finding(
				self::TYPE_REFUND_MISMATCH,
				'critical',
				__( 'WooCommerce and Stripe refunds do not match', 'payment-truth-for-woocommerce' ),
				__( 'The total refunded amount in WooCommerce differs from the amount Stripe reports as refunded.', 'payment-truth-for-woocommerce' ),
				$order,
				$provider
			);
		}

		return $findings;
	}

	/**
	 * Build a local stale-order finding without contacting a provider.
	 *
	 * @param array<string, mixed> $order Order snapshot.
	 * @param int                  $stale_seconds Threshold.
	 * @return array<int, array<string, mixed>>
	 */
	public function local_findings( array $order, $stale_seconds ) {
		if (
			'pending' !== (string) $order['status'] ||
			(int) $order['age_seconds'] < max( 60, (int) $stale_seconds )
		) {
			return array();
		}

		return array(
			$this->finding(
				self::TYPE_STALE_PENDING,
				'warning',
				__( 'Order has been pending longer than expected', 'payment-truth-for-woocommerce' ),
				__( 'This order is still awaiting payment after the configured threshold. Reconcile it before cancelling or fulfilling it.', 'payment-truth-for-woocommerce' ),
				$order,
				array(
					'reference'    => '',
					'status'       => '',
					'amount_minor' => '',
					'currency'     => '',
				)
			),
		);
	}

	/**
	 * Build a missing provider reference finding.
	 *
	 * @param array<string, mixed> $order Order snapshot.
	 * @return array<string, mixed>
	 */
	public function missing_reference_finding( array $order ) {
		$severity = ! empty( $order['is_paid'] ) ? 'critical' : 'warning';

		return $this->finding(
			self::TYPE_MISSING_REFERENCE,
			$severity,
			__( 'Stripe order has no usable payment reference', 'payment-truth-for-woocommerce' ),
			__( 'Payment Truth could not find a Stripe PaymentIntent or charge ID on this order, so provider-side reconciliation is not yet possible.', 'payment-truth-for-woocommerce' ),
			$order,
			array(
				'reference'    => '',
				'status'       => '',
				'amount_minor' => '',
				'currency'     => '',
			)
		);
	}

	/**
	 * Normalize one finding record.
	 *
	 * @param string               $type Finding type.
	 * @param string               $severity Severity.
	 * @param string               $title Title.
	 * @param string               $message Explanation.
	 * @param array<string, mixed> $order Order snapshot.
	 * @param array<string, mixed> $provider Provider snapshot.
	 * @return array<string, mixed>
	 */
	private function finding( $type, $severity, $title, $message, array $order, array $provider ) {
		return array(
			'type'               => $type,
			'severity'           => $severity,
			'title'              => $title,
			'message'            => $message,
			'order_id'           => (int) $order['id'],
			'gateway'            => (string) $order['payment_method'],
			'provider_reference' => isset( $provider['reference'] ) ? (string) $provider['reference'] : '',
			'order_status'       => (string) $order['status'],
			'provider_status'    => isset( $provider['status'] ) ? (string) $provider['status'] : '',
			'order_amount'       => (string) $order['amount_minor'],
			'provider_amount'    => isset( $provider['amount_minor'] ) ? (string) $provider['amount_minor'] : '',
			'currency'           => (string) $order['currency'],
			'details'            => array(
				'order_number'            => (string) $order['number'],
				'age_seconds'             => (int) $order['age_seconds'],
				'order_refunded_minor'    => isset( $order['refunded_minor'] ) ? (string) $order['refunded_minor'] : '0',
				'provider_refunded_minor' => isset( $provider['refunded_minor'] ) ? (string) $provider['refunded_minor'] : '',
			),
		);
	}
}

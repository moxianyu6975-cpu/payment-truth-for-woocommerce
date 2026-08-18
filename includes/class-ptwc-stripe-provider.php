<?php
/**
 * Read-only adapter for the official WooCommerce Stripe Gateway.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes Stripe PaymentIntent and charge responses for the reconciler.
 */
final class PTWC_Stripe_Provider {
	/**
	 * Whether the official Stripe gateway API is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( 'WC_Stripe_API' );
	}

	/**
	 * Whether an order belongs to the official Stripe gateway family.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public function supports_order( $order ) {
		$method = (string) $order->get_payment_method();
		return 'stripe' === $method || 0 === strpos( $method, 'stripe_' );
	}

	/**
	 * Find the most reliable Stripe object reference stored on an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public function get_reference( $order ) {
		$reference = '';

		if ( class_exists( 'WC_Stripe_Order_Helper' ) && method_exists( 'WC_Stripe_Order_Helper', 'get_instance' ) ) {
			$helper = WC_Stripe_Order_Helper::get_instance();
			if ( is_object( $helper ) && method_exists( $helper, 'get_intent_id_from_order' ) ) {
				$reference = (string) $helper->get_intent_id_from_order( $order );
			}
		}

		if ( ! $reference ) {
			$reference = (string) $order->get_meta( '_stripe_intent_id', true );
		}

		if ( ! $reference ) {
			$reference = (string) $order->get_transaction_id();
		}

		return preg_match( '/^(pi|ch)_[A-Za-z0-9_]+$/', $reference ) ? $reference : '';
	}

	/**
	 * Retrieve and normalize provider state.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array{ok:bool, snapshot?:array<string,mixed>, error?:string}
	 */
	public function fetch( $order ) {
		if ( ! $this->is_available() ) {
			return array(
				'ok'    => false,
				'error' => 'stripe_gateway_unavailable',
			);
		}

		$reference = $this->get_reference( $order );
		if ( ! $reference ) {
			return array(
				'ok'    => false,
				'error' => 'missing_reference',
			);
		}

		try {
			if ( 0 === strpos( $reference, 'pi_' ) ) {
				$response = WC_Stripe_API::retrieve( 'payment_intents/' . rawurlencode( $reference ) . '?expand[]=latest_charge' );
				return $this->normalize_intent( $reference, $response );
			}

			$response = WC_Stripe_API::retrieve( 'charges/' . rawurlencode( $reference ) );
			return $this->normalize_charge( $reference, $response );
		} catch ( Throwable $exception ) {
			return array(
				'ok'    => false,
				'error' => sanitize_key( $exception->getMessage() ? 'provider_request_failed' : 'provider_exception' ),
			);
		}
	}

	/**
	 * Normalize a PaymentIntent response.
	 *
	 * @param string $reference PaymentIntent ID.
	 * @param object $response Gateway response object.
	 * @return array{ok:bool, snapshot?:array<string,mixed>, error?:string}
	 */
	private function normalize_intent( $reference, $response ) {
		if ( ! is_object( $response ) || ! empty( $response->error ) || empty( $response->id ) ) {
			return array(
				'ok'    => false,
				'error' => 'provider_response_invalid',
			);
		}

		$status = isset( $response->status ) ? sanitize_key( $response->status ) : 'unknown';
		$amount = isset( $response->amount ) ? (string) $response->amount : '';
		$charge = isset( $response->latest_charge ) && is_object( $response->latest_charge ) ? $response->latest_charge : null;

		if ( 'succeeded' === $status && isset( $response->amount_received ) ) {
			$amount = (string) $response->amount_received;
		}

		return array(
			'ok'       => true,
			'snapshot' => array(
				'reference'               => sanitize_text_field( $reference ),
				'status'                  => $status,
				'amount_minor'            => $amount,
				'currency'                => isset( $response->currency ) ? strtoupper( sanitize_text_field( $response->currency ) ) : '',
				'is_paid'                 => 'succeeded' === $status,
				'is_terminal_failure'     => in_array( $status, array( 'canceled', 'requires_payment_method' ), true ),
				'is_amount_authoritative' => in_array( $status, array( 'succeeded', 'requires_capture' ), true ),
				'refunded_minor'          => $charge && isset( $charge->amount_refunded ) ? (string) $charge->amount_refunded : '',
				'is_refund_authoritative' => $charge && isset( $charge->amount_refunded ),
			),
		);
	}

	/**
	 * Normalize a legacy/direct charge response.
	 *
	 * @param string $reference Charge ID.
	 * @param object $response Gateway response object.
	 * @return array{ok:bool, snapshot?:array<string,mixed>, error?:string}
	 */
	private function normalize_charge( $reference, $response ) {
		if ( ! is_object( $response ) || ! empty( $response->error ) || empty( $response->id ) ) {
			return array(
				'ok'    => false,
				'error' => 'provider_response_invalid',
			);
		}

		$paid     = ! empty( $response->paid );
		$captured = ! empty( $response->captured );
		$failure  = isset( $response->failure_code ) && $response->failure_code;
		$status   = $failure ? 'failed' : ( $captured ? 'succeeded' : ( $paid ? 'authorized' : 'pending' ) );

		return array(
			'ok'       => true,
			'snapshot' => array(
				'reference'               => sanitize_text_field( $reference ),
				'status'                  => $status,
				'amount_minor'            => isset( $response->amount ) ? (string) $response->amount : '',
				'currency'                => isset( $response->currency ) ? strtoupper( sanitize_text_field( $response->currency ) ) : '',
				'is_paid'                 => $paid,
				'is_terminal_failure'     => (bool) $failure,
				'is_amount_authoritative' => $paid || $captured,
				'refunded_minor'          => isset( $response->amount_refunded ) ? (string) $response->amount_refunded : '',
				'is_refund_authoritative' => isset( $response->amount_refunded ),
			),
		);
	}
}

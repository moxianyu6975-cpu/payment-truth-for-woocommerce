<?php
/**
 * Opt-in email and regional webhook alerts.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a minimal, non-PII finding summary.
 */
final class PTWC_Notifier {
	/**
	 * Allowed webhook hosts by channel.
	 *
	 * @var array<string, array<string>>
	 */
	private static $webhook_hosts = array(
		'feishu'   => array( 'open.feishu.cn', 'open.larksuite.com' ),
		'dingtalk' => array( 'oapi.dingtalk.com' ),
		'wecom'    => array( 'qyapi.weixin.qq.com' ),
	);

	/**
	 * Send enabled alerts.
	 *
	 * @param array<string, mixed> $finding Finding data.
	 * @param WC_Order             $order Order used only for its number and admin URL.
	 * @return bool Whether at least one enabled delivery succeeded.
	 */
	public function notify( array $finding, $order ) {
		$settings = PTWC_Installer::get_settings();
		if ( ! $this->meets_threshold( $finding['severity'], $settings['minimum_severity'] ) ) {
			return false;
		}

		$message   = $this->build_message( $finding, $order );
		$delivered = false;

		if ( ! empty( $settings['email_enabled'] ) && is_email( $settings['email_recipient'] ) ) {
			$subject = sprintf(
				/* translators: %s: finding severity. */
				__( '[Payment Truth] %s reconciliation issue', 'payment-truth-for-woocommerce' ),
				strtoupper( sanitize_text_field( $finding['severity'] ) )
			);
			$delivered = wp_mail( $settings['email_recipient'], $subject, $message ) || $delivered;
		}

		$channel = sanitize_key( $settings['webhook_channel'] );
		$url     = isset( $settings['webhook_url'] ) ? (string) $settings['webhook_url'] : '';
		if ( 'none' !== $channel && self::validate_webhook_url( $url, $channel ) ) {
			$delivered = $this->send_webhook( $channel, $url, $message ) || $delivered;
		}

		return $delivered;
	}

	/**
	 * Send a harmless test notification to the configured channel.
	 *
	 * @return bool|WP_Error
	 */
	public function send_test() {
		$settings = PTWC_Installer::get_settings();
		$channel  = sanitize_key( $settings['webhook_channel'] );
		$url      = isset( $settings['webhook_url'] ) ? (string) $settings['webhook_url'] : '';

		if ( 'none' === $channel || ! self::validate_webhook_url( $url, $channel ) ) {
			return new WP_Error( 'ptwc_invalid_webhook', __( 'Save a valid HTTPS webhook URL first.', 'payment-truth-for-woocommerce' ) );
		}

		$message = sprintf(
			/* translators: %s: site name. */
			__( 'Payment Truth test alert from %s. No customer or payment data was sent.', 'payment-truth-for-woocommerce' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return $this->send_webhook( $channel, $url, $message );
	}

	/**
	 * Strictly validate an opted-in webhook URL to reduce SSRF exposure.
	 *
	 * @param string $url Webhook URL.
	 * @param string $channel Channel key.
	 * @return bool
	 */
	public static function validate_webhook_url( $url, $channel ) {
		if ( ! isset( self::$webhook_hosts[ $channel ] ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts ) ||
			'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ||
			empty( $parts['host'] ) ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
		) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ), self::$webhook_hosts[ $channel ], true );
	}

	/**
	 * Build a compact alert with no customer identity fields.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @param WC_Order             $order Order.
	 * @return string
	 */
	private function build_message( array $finding, $order ) {
		$lines = array(
			__( 'Payment Truth found a reconciliation issue.', 'payment-truth-for-woocommerce' ),
			sprintf(
				/* translators: 1: order number, 2: severity. */
				__( 'Order: #%1$s | Severity: %2$s', 'payment-truth-for-woocommerce' ),
				(string) $order->get_order_number(),
				strtoupper( sanitize_text_field( $finding['severity'] ) )
			),
			sanitize_text_field( $finding['title'] ),
			sprintf(
				/* translators: 1: WooCommerce status, 2: provider status. */
				__( 'WooCommerce: %1$s | Stripe: %2$s', 'payment-truth-for-woocommerce' ),
				sanitize_text_field( $finding['order_status'] ),
				sanitize_text_field( $finding['provider_status'] ? $finding['provider_status'] : __( 'not available', 'payment-truth-for-woocommerce' ) )
			),
			$this->order_admin_url( $order ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Deliver one webhook request.
	 *
	 * @param string $channel Channel key.
	 * @param string $url Webhook URL.
	 * @param string $message Message.
	 * @return bool
	 */
	private function send_webhook( $channel, $url, $message ) {
		if ( 'feishu' === $channel ) {
			$body = array(
				'msg_type' => 'text',
				'content'  => array( 'text' => $message ),
			);
		} else {
			$body = array(
				'msgtype' => 'text',
				'text'    => array( 'content' => $message ),
			);
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Compare severity levels.
	 *
	 * @param string $severity Finding severity.
	 * @param string $minimum Configured minimum.
	 * @return bool
	 */
	private function meets_threshold( $severity, $minimum ) {
		$levels = array(
			'info'     => 1,
			'warning'  => 2,
			'critical' => 3,
		);

		return isset( $levels[ $severity ], $levels[ $minimum ] ) && $levels[ $severity ] >= $levels[ $minimum ];
	}

	/**
	 * HPOS-safe admin order link.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function order_admin_url( $order ) {
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			return (string) $order->get_edit_order_url();
		}

		return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
	}
}

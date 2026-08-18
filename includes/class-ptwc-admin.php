<?php
/**
 * WooCommerce admin experience.
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders findings and handles capability/nonce-protected actions.
 */
final class PTWC_Admin {
	const PAGE_SLUG = 'payment-truth';

	/**
	 * Finding persistence.
	 *
	 * @var PTWC_Repository
	 */
	private $repository;

	/**
	 * Reconciliation scanner.
	 *
	 * @var PTWC_Scanner
	 */
	private $scanner;

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
	 * Wire admin collaborators.
	 *
	 * @param PTWC_Repository      $repository Repository.
	 * @param PTWC_Scanner         $scanner Scanner.
	 * @param PTWC_Stripe_Provider $provider Provider.
	 * @param PTWC_Notifier        $notifier Notifier.
	 */
	public function __construct( $repository, $scanner, $provider, $notifier ) {
		$this->repository = $repository;
		$this->scanner    = $scanner;
		$this->provider   = $provider;
		$this->notifier   = $notifier;
	}

	/**
	 * Register admin-only hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ptwc_run_scan', array( $this, 'handle_run_scan' ) );
		add_action( 'admin_post_ptwc_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ptwc_finding_status', array( $this, 'handle_finding_status' ) );
		add_action( 'admin_post_ptwc_test_webhook', array( $this, 'handle_test_webhook' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PTWC_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add the WooCommerce submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		// WooCommerce registers this capability for shop managers and administrators.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		add_submenu_page(
			'woocommerce',
			__( 'Payment Truth', 'payment-truth-for-woocommerce' ),
			__( 'Payment Truth', 'payment-truth-for-woocommerce' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load the small stylesheet only on this plugin screen.
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'ptwc-admin', PTWC_URL . 'assets/admin.css', array(), PTWC_VERSION );
	}

	/**
	 * Add a direct plugin-list link.
	 *
	 * @param array<string> $links Existing links.
	 * @return array<string>
	 */
	public function plugin_action_links( array $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Review findings', 'payment-truth-for-woocommerce' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Render the selected tab.
	 *
	 * @return void
	 */
	public function render_page() {
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'findings';
		if ( ! in_array( $tab, array( 'findings', 'settings', 'about' ), true ) ) {
			$tab = 'findings';
		}
		?>
		<div class="wrap ptwc-wrap">
			<div class="ptwc-heading">
				<div>
					<h1><?php esc_html_e( 'Payment Truth for WooCommerce', 'payment-truth-for-woocommerce' ); ?></h1>
					<p><?php esc_html_e( 'Read-only reconciliation between WooCommerce orders and Stripe.', 'payment-truth-for-woocommerce' ); ?></p>
				</div>
				<?php if ( 'findings' === $tab ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ptwc_run_scan">
						<?php wp_nonce_field( 'ptwc_run_scan' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Run scan now', 'payment-truth-for-woocommerce' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<?php $this->render_notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php $this->tab_link( 'findings', __( 'Findings', 'payment-truth-for-woocommerce' ), $tab ); ?>
				<?php $this->tab_link( 'settings', __( 'Settings', 'payment-truth-for-woocommerce' ), $tab ); ?>
				<?php $this->tab_link( 'about', __( 'How it works', 'payment-truth-for-woocommerce' ), $tab ); ?>
			</nav>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings();
			} elseif ( 'about' === $tab ) {
				$this->render_about();
			} else {
				$this->render_findings();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Process a manual scan request.
	 *
	 * @return void
	 */
	public function handle_run_scan() {
		$this->assert_capability();
		check_admin_referer( 'ptwc_run_scan' );

		$result = $this->scanner->scan_recent();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'scan_locked' );
		}

		$this->redirect_with_notice( 'scan_complete' );
	}

	/**
	 * Save bounded, validated settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->assert_capability();
		check_admin_referer( 'ptwc_save_settings' );

		$current = PTWC_Installer::get_settings();
		$channel = isset( $_POST['webhook_channel'] ) ? sanitize_key( wp_unslash( $_POST['webhook_channel'] ) ) : 'none';
		if ( ! in_array( $channel, array( 'none', 'feishu', 'dingtalk', 'wecom' ), true ) ) {
			$channel = 'none';
		}

		$new_url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
		if ( ! empty( $_POST['clear_webhook'] ) || 'none' === $channel ) {
			$webhook_url = '';
		} elseif ( $new_url ) {
			if ( ! PTWC_Notifier::validate_webhook_url( $new_url, $channel ) ) {
				$this->redirect_with_notice( 'invalid_webhook', 'settings' );
			}
			$webhook_url = $new_url;
		} else {
			$webhook_url = (string) $current['webhook_url'];
			if ( $webhook_url && ! PTWC_Notifier::validate_webhook_url( $webhook_url, $channel ) ) {
				$webhook_url = '';
			}
		}

		$severity = isset( $_POST['minimum_severity'] ) ? sanitize_key( wp_unslash( $_POST['minimum_severity'] ) ) : 'warning';
		if ( ! in_array( $severity, array( 'warning', 'critical' ), true ) ) {
			$severity = 'warning';
		}

		$settings = array(
			'lookback_days'       => min( 90, max( 1, absint( isset( $_POST['lookback_days'] ) ? $_POST['lookback_days'] : 7 ) ) ),
			'max_orders'          => min( 500, max( 10, absint( isset( $_POST['max_orders'] ) ? $_POST['max_orders'] : 100 ) ) ),
			'stale_minutes'       => min( 10080, max( 5, absint( isset( $_POST['stale_minutes'] ) ? $_POST['stale_minutes'] : 30 ) ) ),
			'email_enabled'       => empty( $_POST['email_enabled'] ) ? 0 : 1,
			'email_recipient'     => isset( $_POST['email_recipient'] ) ? sanitize_email( wp_unslash( $_POST['email_recipient'] ) ) : '',
			'webhook_channel'     => $channel,
			'webhook_url'         => $webhook_url,
			'minimum_severity'    => $severity,
			'delete_on_uninstall' => empty( $_POST['delete_on_uninstall'] ) ? 0 : 1,
		);

		update_option( 'ptwc_settings', $settings, false );
		$this->redirect_with_notice( 'settings_saved', 'settings' );
	}

	/**
	 * Ignore or reopen a finding.
	 *
	 * @return void
	 */
	public function handle_finding_status() {
		$this->assert_capability();
		$id     = isset( $_POST['finding_id'] ) ? absint( $_POST['finding_id'] ) : 0;
		$status = isset( $_POST['finding_status'] ) ? sanitize_key( wp_unslash( $_POST['finding_status'] ) ) : '';
		check_admin_referer( 'ptwc_finding_status_' . $id );

		$this->repository->set_status( $id, $status );
		$this->redirect_with_notice( 'finding_updated' );
	}

	/**
	 * Send a configured webhook test.
	 *
	 * @return void
	 */
	public function handle_test_webhook() {
		$this->assert_capability();
		check_admin_referer( 'ptwc_test_webhook' );

		$result = $this->notifier->send_test();
		$this->redirect_with_notice( true === $result ? 'webhook_sent' : 'webhook_failed', 'settings' );
	}

	/**
	 * Dashboard cards, filters, and finding table.
	 *
	 * @return void
	 */
	private function render_findings() {
		$summary = $this->repository->summary();
		$last    = get_option( PTWC_Scanner::LAST_SCAN_KEY, array() );
		?>
		<div class="ptwc-cards">
			<?php $this->metric_card( __( 'Open critical', 'payment-truth-for-woocommerce' ), $summary['open_critical'], 'critical' ); ?>
			<?php $this->metric_card( __( 'Open warnings', 'payment-truth-for-woocommerce' ), $summary['open_warning'], 'warning' ); ?>
			<?php $this->metric_card( __( 'Resolved', 'payment-truth-for-woocommerce' ), $summary['resolved'], 'good' ); ?>
			<?php
			$this->metric_card(
				__( 'Stripe connection', 'payment-truth-for-woocommerce' ),
				$this->provider->is_available() ? __( 'Ready', 'payment-truth-for-woocommerce' ) : __( 'Not detected', 'payment-truth-for-woocommerce' ),
				$this->provider->is_available() ? 'good' : 'warning'
			);
			?>
		</div>

		<?php if ( ! $this->provider->is_available() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The official WooCommerce Stripe Gateway is not active. Payment Truth will wait and will not ask you to copy API keys into this plugin.', 'payment-truth-for-woocommerce' ); ?></p></div>
		<?php endif; ?>

		<p class="description ptwc-last-scan">
			<?php
			if ( ! empty( $last['finished_gmt'] ) ) {
				echo esc_html(
					sprintf(
						/* translators: 1: scan time, 2: order count, 3: provider read count. */
						__( 'Last scan: %1$s UTC · %2$d Stripe orders · %3$d provider reads', 'payment-truth-for-woocommerce' ),
						$last['finished_gmt'],
						isset( $last['orders_scanned'] ) ? (int) $last['orders_scanned'] : 0,
						isset( $last['provider_reads'] ) ? (int) $last['provider_reads'] : 0
					)
				);
			} else {
				esc_html_e( 'No scan has run yet. Start with a manual scan; hourly scans will continue automatically.', 'payment-truth-for-woocommerce' );
			}
			?>
		</p>

		<?php $this->render_findings_table(); ?>
		<?php
	}

	/**
	 * Render filtered findings.
	 *
	 * @return void
	 */
	private function render_findings_table() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only table filters.
		$status   = isset( $_GET['finding_status'] ) ? sanitize_key( wp_unslash( $_GET['finding_status'] ) ) : 'open';
		$severity = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $status, array( 'open', 'ignored', 'resolved', 'all' ), true ) ) {
			$status = 'open';
		}

		$result = $this->repository->query(
			array(
				'status'   => $status,
				'severity' => $severity,
				'page'     => $page,
				'per_page' => 20,
			)
		);
		?>
		<form method="get" class="ptwc-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<select name="finding_status">
				<?php $this->option( 'open', __( 'Open', 'payment-truth-for-woocommerce' ), $status ); ?>
				<?php $this->option( 'ignored', __( 'Ignored', 'payment-truth-for-woocommerce' ), $status ); ?>
				<?php $this->option( 'resolved', __( 'Resolved', 'payment-truth-for-woocommerce' ), $status ); ?>
				<?php $this->option( 'all', __( 'All statuses', 'payment-truth-for-woocommerce' ), $status ); ?>
			</select>
			<select name="severity">
				<?php $this->option( '', __( 'All severities', 'payment-truth-for-woocommerce' ), $severity ); ?>
				<?php $this->option( 'critical', __( 'Critical', 'payment-truth-for-woocommerce' ), $severity ); ?>
				<?php $this->option( 'warning', __( 'Warning', 'payment-truth-for-woocommerce' ), $severity ); ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'payment-truth-for-woocommerce' ); ?></button>
		</form>

		<div class="ptwc-table-wrap">
			<table class="widefat fixed striped ptwc-table">
				<thead><tr>
					<th><?php esc_html_e( 'Severity', 'payment-truth-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Order', 'payment-truth-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Finding', 'payment-truth-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'WooCommerce / Stripe', 'payment-truth-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'payment-truth-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Last seen (UTC)', 'payment-truth-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Action', 'payment-truth-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $result['items'] ) ) : ?>
					<tr><td colspan="7" class="ptwc-empty"><?php esc_html_e( 'No findings match this view.', 'payment-truth-for-woocommerce' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['items'] as $item ) : ?>
						<?php $this->render_finding_row( $item ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		$total_pages = (int) ceil( $result['total'] / 20 );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg(
							'paged',
							'%#%',
							$this->page_url(
								array(
									'finding_status' => $status,
									'severity'       => $severity,
								)
							)
						),
						'format'    => '',
						'current'   => $page,
						'total'     => $total_pages,
						'prev_text' => '&lsaquo;',
						'next_text' => '&rsaquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Render one finding row.
	 *
	 * @param object $item Finding row.
	 * @return void
	 */
	private function render_finding_row( $item ) {
		$order       = wc_get_order( $item->order_id );
		$order_label = $order ? '#' . $order->get_order_number() : '#' . absint( $item->order_id );
		$order_url   = $order && method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . absint( $item->order_id ) . '&action=edit' );
		$new_status  = 'ignored' === $item->finding_status ? 'open' : 'ignored';
		$action_text = 'ignored' === $item->finding_status ? __( 'Reopen', 'payment-truth-for-woocommerce' ) : __( 'Ignore', 'payment-truth-for-woocommerce' );
		$amount_html = $this->format_minor_amount( $item->order_amount, $item->currency );

		if ( PTWC_Reconciler::TYPE_REFUND_MISMATCH === $item->type ) {
			$details = json_decode( $item->details_json, true );
			if ( is_array( $details ) ) {
				$woo_refund    = isset( $details['order_refunded_minor'] ) ? $details['order_refunded_minor'] : '0';
				$stripe_refund = isset( $details['provider_refunded_minor'] ) ? $details['provider_refunded_minor'] : '0';
				$amount_html   = sprintf(
					/* translators: 1: WooCommerce refund amount, 2: Stripe refund amount. */
					__( 'Refund: %1$s / %2$s', 'payment-truth-for-woocommerce' ),
					$this->format_minor_amount( $woo_refund, $item->currency ),
					$this->format_minor_amount( $stripe_refund, $item->currency )
				);
			}
		}
		?>
		<tr>
			<td><span class="ptwc-badge ptwc-badge--<?php echo esc_attr( $item->severity ); ?>"><?php echo esc_html( ucfirst( $item->severity ) ); ?></span></td>
			<td><a href="<?php echo esc_url( $order_url ); ?>"><?php echo esc_html( $order_label ); ?></a><br><code><?php echo esc_html( $item->gateway ); ?></code></td>
			<td><strong><?php echo esc_html( $item->title ); ?></strong><p><?php echo esc_html( $item->message ); ?></p></td>
			<td><code><?php echo esc_html( $item->order_status ); ?></code><br><code><?php echo esc_html( $item->provider_status ? $item->provider_status : '—' ); ?></code></td>
			<td><?php echo wp_kses_post( $amount_html ); ?>
			<?php
			if ( PTWC_Reconciler::TYPE_REFUND_MISMATCH !== $item->type ) :
				?>
				<br><?php echo $item->provider_amount ? wp_kses_post( $this->format_minor_amount( $item->provider_amount, $item->currency ) ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static fallback or escaped price. ?><?php endif; ?></td>
			<td><?php echo esc_html( $item->last_seen_gmt ); ?></td>
			<td>
				<?php if ( 'resolved' !== $item->finding_status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ptwc_finding_status">
						<input type="hidden" name="finding_id" value="<?php echo esc_attr( $item->id ); ?>">
						<input type="hidden" name="finding_status" value="<?php echo esc_attr( $new_status ); ?>">
						<?php wp_nonce_field( 'ptwc_finding_status_' . $item->id ); ?>
						<button class="button button-small"><?php echo esc_html( $action_text ); ?></button>
					</form>
				<?php else : ?>
					<span class="ptwc-muted"><?php esc_html_e( 'Auto-resolved', 'payment-truth-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render scan and notification settings.
	 *
	 * @return void
	 */
	private function render_settings() {
		$settings = PTWC_Installer::get_settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptwc-settings">
			<input type="hidden" name="action" value="ptwc_save_settings">
			<?php wp_nonce_field( 'ptwc_save_settings' ); ?>

			<h2><?php esc_html_e( 'Scan window', 'payment-truth-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $this->number_setting( 'lookback_days', __( 'Look back', 'payment-truth-for-woocommerce' ), $settings['lookback_days'], 1, 90, __( 'days of recent orders', 'payment-truth-for-woocommerce' ) ); ?>
				<?php $this->number_setting( 'max_orders', __( 'Maximum orders per scan', 'payment-truth-for-woocommerce' ), $settings['max_orders'], 10, 500, __( 'Keeps each hourly run bounded.', 'payment-truth-for-woocommerce' ) ); ?>
				<?php $this->number_setting( 'stale_minutes', __( 'Pending-order threshold', 'payment-truth-for-woocommerce' ), $settings['stale_minutes'], 5, 10080, __( 'minutes before a pending Stripe order becomes a warning', 'payment-truth-for-woocommerce' ) ); ?>
			</table>

			<h2><?php esc_html_e( 'Opt-in alerts', 'payment-truth-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Alerts include only order number, statuses, issue title, and an admin link. They never include customer identity or card data.', 'payment-truth-for-woocommerce' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'payment-truth-for-woocommerce' ); ?></th>
					<td>
						<label><input type="checkbox" name="email_enabled" value="1" <?php checked( $settings['email_enabled'], 1 ); ?>> <?php esc_html_e( 'Send email for newly opened findings', 'payment-truth-for-woocommerce' ); ?></label><br>
						<input type="email" class="regular-text" name="email_recipient" value="<?php echo esc_attr( $settings['email_recipient'] ); ?>" placeholder="ops@example.com">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ptwc-webhook-channel"><?php esc_html_e( 'Team webhook', 'payment-truth-for-woocommerce' ); ?></label></th>
					<td>
						<select id="ptwc-webhook-channel" name="webhook_channel">
							<?php $this->option( 'none', __( 'Disabled', 'payment-truth-for-woocommerce' ), $settings['webhook_channel'] ); ?>
							<?php $this->option( 'feishu', __( 'Feishu / Lark', 'payment-truth-for-woocommerce' ), $settings['webhook_channel'] ); ?>
							<?php $this->option( 'dingtalk', __( 'DingTalk', 'payment-truth-for-woocommerce' ), $settings['webhook_channel'] ); ?>
							<?php $this->option( 'wecom', __( 'WeCom', 'payment-truth-for-woocommerce' ), $settings['webhook_channel'] ); ?>
						</select>
						<p><input type="password" class="large-text" name="webhook_url" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['webhook_url'] ? __( 'Webhook saved — leave blank to keep it', 'payment-truth-for-woocommerce' ) : 'https://…' ); ?>"></p>
						<label><input type="checkbox" name="clear_webhook" value="1"> <?php esc_html_e( 'Remove the saved webhook URL', 'payment-truth-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only official HTTPS webhook hosts are accepted. Saving a webhook opts this site into sending alert summaries to that service.', 'payment-truth-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ptwc-minimum-severity"><?php esc_html_e( 'Minimum alert severity', 'payment-truth-for-woocommerce' ); ?></label></th>
					<td><select id="ptwc-minimum-severity" name="minimum_severity">
						<?php $this->option( 'warning', __( 'Warning and critical', 'payment-truth-for-woocommerce' ), $settings['minimum_severity'] ); ?>
						<?php $this->option( 'critical', __( 'Critical only', 'payment-truth-for-woocommerce' ), $settings['minimum_severity'] ); ?>
					</select></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Data removal', 'payment-truth-for-woocommerce' ); ?></h2>
			<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $settings['delete_on_uninstall'], 1 ); ?>> <?php esc_html_e( 'Delete findings and settings when the plugin is uninstalled', 'payment-truth-for-woocommerce' ); ?></label>

			<?php submit_button( __( 'Save settings', 'payment-truth-for-woocommerce' ) ); ?>
		</form>

		<?php if ( 'none' !== $settings['webhook_channel'] && $settings['webhook_url'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ptwc-test-form">
				<input type="hidden" name="action" value="ptwc_test_webhook">
				<?php wp_nonce_field( 'ptwc_test_webhook' ); ?>
				<button class="button"><?php esc_html_e( 'Send webhook test', 'payment-truth-for-woocommerce' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Explain scope and external services.
	 *
	 * @return void
	 */
	private function render_about() {
		?>
		<div class="ptwc-about">
			<h2><?php esc_html_e( 'What Payment Truth checks', 'payment-truth-for-woocommerce' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Stripe succeeded while WooCommerce still considers the order unpaid.', 'payment-truth-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'WooCommerce considers an order paid while Stripe reports a terminal failure.', 'payment-truth-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'Order amount or currency differs from Stripe.', 'payment-truth-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'Refund totals differ between WooCommerce and Stripe.', 'payment-truth-for-woocommerce' ); ?></li>
				<li><?php esc_html_e( 'A Stripe order remains pending too long or has no usable payment reference.', 'payment-truth-for-woocommerce' ); ?></li>
			</ul>
			<p><strong><?php esc_html_e( 'Safety boundary:', 'payment-truth-for-woocommerce' ); ?></strong> <?php esc_html_e( 'This version never changes an order, captures money, issues a refund, or stores Stripe credentials.', 'payment-truth-for-woocommerce' ); ?></p>

			<h2><?php esc_html_e( 'External services', 'payment-truth-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'For Stripe orders, the plugin uses the official WooCommerce Stripe Gateway connection to read the matching PaymentIntent or charge from Stripe. Stripe receives its object ID and the normal authentication supplied by that gateway.', 'payment-truth-for-woocommerce' ); ?></p>
			<p><?php esc_html_e( 'Optional Feishu/Lark, DingTalk, and WeCom webhooks are disabled by default. If enabled, the selected service receives a minimal finding summary and an admin order link.', 'payment-truth-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render an admin notice selected by a safe message key.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message key follows a nonce-protected redirect.
		$key      = isset( $_GET['ptwc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ptwc_notice'] ) ) : '';
		$messages = array(
			'scan_complete'   => array( 'success', __( 'Scan complete. The findings list is up to date.', 'payment-truth-for-woocommerce' ) ),
			'scan_locked'     => array( 'warning', __( 'A scan is already running. Try again shortly.', 'payment-truth-for-woocommerce' ) ),
			'settings_saved'  => array( 'success', __( 'Settings saved.', 'payment-truth-for-woocommerce' ) ),
			'invalid_webhook' => array( 'error', __( 'That webhook URL does not match the selected service’s official HTTPS host.', 'payment-truth-for-woocommerce' ) ),
			'finding_updated' => array( 'success', __( 'Finding status updated.', 'payment-truth-for-woocommerce' ) ),
			'webhook_sent'    => array( 'success', __( 'Webhook test sent.', 'payment-truth-for-woocommerce' ) ),
			'webhook_failed'  => array( 'error', __( 'Webhook test failed. Check the URL and the service response.', 'payment-truth-for-woocommerce' ) ),
		);

		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $key ][0] ),
			esc_html( $messages[ $key ][1] )
		);
	}

	/**
	 * Render one navigation tab.
	 *
	 * @param string $key Tab key.
	 * @param string $label Label.
	 * @param string $active Active tab.
	 * @return void
	 */
	private function tab_link( $key, $label, $active ) {
		printf(
			'<a class="nav-tab %1$s" href="%2$s">%3$s</a>',
			$key === $active ? 'nav-tab-active' : '',
			esc_url( $this->page_url( array( 'tab' => $key ) ) ),
			esc_html( $label )
		);
	}

	/**
	 * Render a dashboard metric.
	 *
	 * @param string     $label Label.
	 * @param int|string $value Value.
	 * @param string     $tone Visual tone.
	 * @return void
	 */
	private function metric_card( $label, $value, $tone ) {
		?>
		<div class="ptwc-card ptwc-card--<?php echo esc_attr( $tone ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Render a numeric settings row.
	 *
	 * @param string $name Field name.
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @param string $description Description.
	 * @return void
	 */
	private function number_setting( $name, $label, $value, $min, $max, $description ) {
		?>
		<tr>
			<th scope="row"><label for="ptwc-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input id="ptwc-<?php echo esc_attr( $name ); ?>" type="number" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>"> <span class="description"><?php echo esc_html( $description ); ?></span></td>
		</tr>
		<?php
	}

	/**
	 * Render an option.
	 *
	 * @param string $value Value.
	 * @param string $label Label.
	 * @param string $selected Current value.
	 * @return void
	 */
	private function option( $value, $label, $selected ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) );
	}

	/**
	 * Format a stored smallest-unit value for humans.
	 *
	 * @param string $minor Smallest unit amount.
	 * @param string $currency ISO currency.
	 * @return string
	 */
	private function format_minor_amount( $minor, $currency ) {
		$zero_decimal  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$three_decimal = array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );
		$currency      = strtoupper( $currency );
		$decimals      = in_array( $currency, $zero_decimal, true ) ? 0 : ( in_array( $currency, $three_decimal, true ) ? 3 : 2 );
		$amount        = (float) $minor / ( 10 ** $decimals );

		return wc_price( $amount, array( 'currency' => $currency ) );
	}

	/**
	 * Require the WooCommerce management capability.
	 *
	 * @return void
	 */
	private function assert_capability() {
		// WooCommerce registers this capability for shop managers and administrators.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Payment Truth.', 'payment-truth-for-woocommerce' ) );
		}
	}

	/**
	 * Build the plugin page URL.
	 *
	 * @param array<string,string> $args Query arguments.
	 * @return string
	 */
	private function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect to a safe notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $tab Optional tab.
	 * @return void
	 */
	private function redirect_with_notice( $notice, $tab = '' ) {
		$args = array( 'ptwc_notice' => sanitize_key( $notice ) );
		if ( $tab ) {
			$args['tab'] = sanitize_key( $tab );
		}

		wp_safe_redirect( $this->page_url( $args ) );
		exit;
	}
}

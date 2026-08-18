=== Payment Truth for WooCommerce ===
Contributors: pluginmosaic
Tags: woocommerce, stripe, payments, refunds, orders
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find Stripe payments that succeeded while WooCommerce orders stay unpaid, plus amount, currency, refund, and status mismatches. Read-only.

== Description ==

Payment Truth helps WooCommerce stores find Stripe payments that succeeded while the matching order is still pending, on hold, failed, or otherwise unpaid. It also detects WooCommerce and Stripe amount, currency, refund, and payment-status mismatches.

The plugin is a read-only second opinion. It never changes an order status, captures a payment, issues a refund, retries a payment, or replays a webhook.

= When this plugin helps =

Use Payment Truth when you need to investigate situations such as:

* Stripe shows a successful payment, but the WooCommerce order is still unpaid.
* WooCommerce marks an order paid, but Stripe reports a cancelled or failed payment.
* The order total and the amount authorized or collected by Stripe do not match.
* WooCommerce and Stripe use different currencies for the same order.
* WooCommerce and Stripe report different refunded totals.
* A Stripe order remains pending longer than the configured threshold.
* A Stripe order has no usable PaymentIntent or charge reference.

= How it works =

1. Scans a bounded window of recent orders created by the official WooCommerce Stripe Gateway.
2. Finds the Stripe PaymentIntent or charge reference stored on each supported order.
3. Uses the official gateway's existing Stripe connection to read the matching provider record.
4. Compares payment status, gross amount, currency, and refunded total.
5. Places mismatches in one WooCommerce admin queue for investigation.

A finding means that the two records disagree. It does not by itself prove that a webhook failed or identify the root cause. Delayed webhooks, interrupted checkouts, manual order edits, refunds, plugin conflicts, or integration failures can all produce inconsistent evidence. Review the order notes, Stripe record, and relevant logs before changing or fulfilling an order.

= Designed to fail safely =

* Read-only: never captures, refunds, retries, or changes an order.
* No extra Stripe keys: uses the official gateway's existing connection.
* No customer PII stored in the findings table.
* Five-minute grace period reduces false positives during normal asynchronous updates.
* Bounded hourly scans and a manual scan button.
* Email, Feishu/Lark, DingTalk, and WeCom alerts are opt-in and fire only for newly opened findings.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).

== Installation ==

1. Install and activate WooCommerce.
2. Install and configure the official WooCommerce Stripe Gateway.
3. Install Payment Truth from Plugins > Add New, or upload its ZIP, and activate it.
4. Open WooCommerce > Payment Truth.
5. Select "Run scan now" and review the findings.
6. Adjust the scan window and optionally enable alerts under Settings.

No Stripe secret key is entered into Payment Truth.

== Frequently Asked Questions ==

= Stripe says the payment succeeded, but the WooCommerce order is still pending. Can Payment Truth detect it? =

Yes, when the order was created by the official WooCommerce Stripe Gateway and contains a usable PaymentIntent or charge reference. Payment Truth waits five minutes before reporting this status disagreement to reduce noise from normal asynchronous updates.

= Does a mismatch prove that a Stripe webhook failed? =

No. A finding proves only that the WooCommerce and Stripe records disagree when scanned. Check the order notes, Stripe event and payment record, webhook delivery history, logs, and recent site changes to determine the cause.

= Does the plugin change order statuses or move money? =

No. Version 0.1.0 is deliberately read-only. It reports evidence and links to the order so a store operator can investigate.

= Does Payment Truth require another Stripe secret key? =

No. It uses the authentication already configured by the official WooCommerce Stripe Gateway. Payment Truth does not store Stripe credentials.

= Which Stripe plugin is supported? =

The initial release supports the official WooCommerce Stripe Gateway and its `stripe` or `stripe_*` payment methods. Other Stripe gateways may store different references and are not queried.

= Can WooCommerce and Stripe amounts really differ? =

They can contain different stored values after manual edits, interrupted integrations, unusual capture flows, or other data drift. Payment Truth compares the WooCommerce gross order total with an authoritative Stripe amount in the currency's smallest unit. A finding asks you to investigate; it does not claim that Stripe charged the wrong amount.

= Can it detect refund total mismatches? =

Yes. When Stripe supplies an authoritative refunded amount, Payment Truth compares it with WooCommerce's total refunded amount and reports a disagreement.

= Will it send customer data to an alert service? =

No customer identity or card fields are included. An opted-in alert contains the order number, finding severity/title, WooCommerce and Stripe statuses, and an admin order URL.

= Why is a mismatch not shown immediately? =

Payment updates are asynchronous. Payment Truth waits five minutes before reporting status disagreements and scans hourly by default. You can also run a manual scan.

= Which orders are scanned? =

Only recent orders that use a supported official Stripe payment method are scanned. The lookback period and maximum orders per scan are configurable, keeping scheduled and manual scans bounded.

= Does it work with HPOS? =

Yes. Orders are read through WooCommerce CRUD APIs and the plugin declares HPOS compatibility.

== External services ==

Payment Truth connects to external services only for features described below.

= Stripe =

For each supported order with a Stripe object reference, Payment Truth asks Stripe for the matching PaymentIntent or charge. The request is made through the official WooCommerce Stripe Gateway and uses that gateway's existing authentication. Stripe receives the object ID and the standard request metadata sent by the official gateway. This read is required for provider-side reconciliation.

Stripe API documentation: https://docs.stripe.com/api
Stripe Services Agreement: https://stripe.com/legal/ssa
Stripe Privacy Policy: https://stripe.com/privacy

= Optional team webhooks =

Feishu/Lark, DingTalk, and WeCom webhooks are disabled by default. If a store administrator selects a channel and saves its official HTTPS webhook URL, Payment Truth sends a minimal alert summary to that service for newly opened findings. The summary contains an order number, severity, finding title, WooCommerce and Stripe statuses, and an admin order URL. It does not contain customer identity or card data.

Disable the channel or remove the saved webhook URL at any time under WooCommerce > Payment Truth > Settings.

Feishu webhook documentation: https://open.feishu.cn/document/ukTMukTMukTM/ucTM5YjL3ETO24yNxkjN
Feishu User Terms of Service: https://www.feishu.cn/feishu-lite-terms
Feishu Privacy Policy: https://www.feishu.cn/feishu-lite-privacy

Lark Customer Terms of Service: https://www.larksuite.com/en_us/customer-terms-of-service
Lark Privacy Policy: https://www.larksuite.com/en_us/privacy-policy

DingTalk Terms of Service: https://terms.alicdn.com/legal-agreement/terms/suit_bu1_dingtalk/suit_bu1_dingtalk202010200940_84493.html
DingTalk Privacy Policy: https://terms.alicdn.com/legal-agreement/terms/suit_bu1_dingtalk/suit_bu1_dingtalk202010070946_49604.html

WeCom Terms of Service: https://work.weixin.qq.com/nl/eula
WeCom Privacy Policy: https://work.weixin.qq.com/nl/privacy

== Privacy ==

The plugin stores normalized reconciliation evidence in the WordPress database: order ID, gateway ID, provider object ID, issue type/severity, WooCommerce and provider statuses, gross/refund amounts, currency, and timestamps. It does not store customer names, addresses, email addresses, card data, or Stripe credentials.

Email and team-webhook alerts are disabled by default. Findings and settings remain after deactivation. Administrators can opt into deleting them during uninstall.

== Screenshots ==

1. Reconciliation dashboard with severity totals and provider readiness.
2. Finding queue with WooCommerce and Stripe evidence.
3. Bounded scan and opt-in alert settings.

== Changelog ==

= 0.1.0 =

* Initial read-only Stripe reconciliation release.
* Detect status, amount, currency, refund, stale-pending, and missing-reference issues.
* Add hourly and manual scans with deduplication and automatic resolution.
* Add opt-in email, Feishu/Lark, DingTalk, and WeCom alerts.
* Declare WooCommerce HPOS compatibility.

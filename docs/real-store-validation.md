---
layout: default
title: Payment Truth Real-Store Validation
description: Help validate read-only WooCommerce Stripe reconciliation on a real store without sharing customer or payment secrets.
permalink: /real-store-validation/
---

# Help validate Payment Truth on a real store

We are looking for **five WooCommerce store owners, maintainers, or agencies** who use the official WooCommerce Stripe Gateway and can spend about ten minutes running one read-only scan.

[Install Payment Truth from WordPress.org](https://wordpress.org/plugins/payment-truth-for-woocommerce/) · [Share redacted scan feedback](https://github.com/moxianyu6975-cpu/payment-truth-for-woocommerce/issues/new?template=real_store_feedback.yml)

## Who this is for

Your store is a useful fit when it has:

- WordPress 6.4 or later and WooCommerce 8.2 or later.
- The official WooCommerce Stripe Gateway.
- At least one recent order paid or attempted through a `stripe` or `stripe_*` payment method.
- A store administrator who can install a plugin and review WooCommerce orders.

Test-mode, staging, and live stores are all useful. Payment Truth uses the gateway connection already configured on that store and does not ask for another Stripe secret key.

## Ten-minute validation

1. Install and activate Payment Truth.
2. Open **WooCommerce → Payment Truth**.
3. Select **Run scan now**.
4. Review the scan-health message and any findings.
5. Do not change fulfillment, payment, refund, or order state based on a finding alone. Check the linked order, exact Stripe object, events, webhook delivery history, and logs first.
6. [Submit the redacted feedback form](https://github.com/moxianyu6975-cpu/payment-truth-for-woocommerce/issues/new?template=real_store_feedback.yml), even if the scan found no mismatch.

## What feedback is useful

- WordPress, WooCommerce, Stripe Gateway, Payment Truth, and PHP versions.
- Whether HPOS is enabled and whether the scan used test or live mode.
- Approximate counts shown by scan health: supported orders, provider reads, provider errors, and findings.
- Finding categories, if any, without order numbers or Stripe object IDs.
- What was clear, what was confusing, and which missing capability would save the most time.

## Never share these details

Do not post a store URL, customer information, order number, PaymentIntent or charge ID, API key, webhook signing secret, webhook URL, access token, log containing personal data, or screenshot that exposes any of those values.

## What participants receive

- Maintainer help interpreting redacted scan health and findings.
- Priority consideration for reproducible bugs and recurring workflow needs.
- A direct voice in deciding the scope of version 0.3.0.

Participation is free. We do not offer compensation or ask participants for a review. The goal is to learn whether the plugin solves a real operational problem safely.

## Safety boundary

Payment Truth is a read-only second opinion. It never captures, refunds, retries, replays webhooks, or changes an order. A finding proves only that the two records disagreed when scanned; it does not determine the root cause or which record should change.

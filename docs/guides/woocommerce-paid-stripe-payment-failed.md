---
layout: default
title: WooCommerce Says Paid but Stripe Reports a Failed or Cancelled Payment
slug: woocommerce-paid-stripe-payment-failed
description: How to investigate a WooCommerce order marked paid when the matching Stripe payment is failed or cancelled.
updated: 2026-08-18
primary_query: WooCommerce paid but Stripe payment failed
permalink: /guides/woocommerce-paid-stripe-payment-failed/
---

# WooCommerce Says Paid but Stripe Reports a Failed or Cancelled Payment

## Quick answer

If WooCommerce marks an order paid while the matching Stripe PaymentIntent or charge is in a terminal failure state, pause operational decisions and verify the linkage between the two records. A WooCommerce status alone is not proof that Stripe collected the expected funds, and a Stripe failure attached to the wrong reference is not proof that the order is unpaid.

## Confirm that the records belong together

Start with identifiers rather than status labels:

1. Find the PaymentIntent or charge ID stored on the WooCommerce order.
2. Open that exact object in the correct Stripe account and mode.
3. Compare gross amount and currency.
4. Compare timestamps with the order notes and payment-complete event.
5. Check whether a later retry created a different successful PaymentIntent or charge.

This prevents a failed first attempt from being confused with a successful later attempt.

## Possible explanations to investigate

The mismatch may follow a manual WooCommerce status change, custom checkout code, a plugin conflict, an incorrect transaction reference, a retry flow, delayed data, or another integration issue. These are investigation paths, not conclusions.

## Where Payment Truth helps

[Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/) compares recent official WooCommerce Stripe Gateway orders with their matching Stripe records. After its grace period, it reports a critical finding when WooCommerce considers the order paid but Stripe reports a cancelled or terminally failed payment.

The plugin is intentionally read-only. It does not mark the order unpaid, cancel fulfillment, retry payment, or contact the customer. The store operator keeps control of every state-changing decision.

## Safe next steps

* Preserve order notes and relevant logs.
* Confirm whether another Stripe payment object succeeded for the same checkout.
* Review recent manual edits and checkout customizations.
* Follow the store's documented payment-review and fulfillment procedure.
* Escalate to a qualified developer or payment support team when the evidence is incomplete.

## Official references

* [WooCommerce: Stripe extension and order statuses](https://woocommerce.com/document/stripe/admin-experience/order-statuses/)
* [WooCommerce: troubleshooting orders](https://woocommerce.com/document/managing-orders/troubleshooting-orders/)
* [Stripe: PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle)

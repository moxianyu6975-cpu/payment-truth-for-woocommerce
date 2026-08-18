---
layout: default
title: Stripe Payment Succeeded but the WooCommerce Order Is Still Pending
slug: stripe-payment-succeeded-woocommerce-order-pending
description: A safe evidence checklist for a Stripe payment that succeeded while the matching WooCommerce order remains pending or unpaid.
updated: 2026-08-18
primary_query: Stripe payment succeeded but WooCommerce order pending
permalink: /guides/stripe-payment-succeeded-woocommerce-order-pending/
---

# Stripe Payment Succeeded but the WooCommerce Order Is Still Pending

## Quick answer

Stripe can report a successful payment while WooCommerce still treats the matching order as pending, on hold, failed, or otherwise unpaid. That disagreement does not automatically mean the customer should be charged again or that the order should be marked paid manually. First confirm that both records refer to the same transaction, then inspect order notes, Stripe events, webhook delivery, logs, and timing.

## What the disagreement means

WooCommerce order status and Stripe payment status are separate records. The official Stripe extension normally updates the WooCommerce order after the payment flow and relevant webhook processing complete. A delay, configuration problem, interrupted request, plugin conflict, or manual edit can leave those records out of sync.

The observable fact is simple:

* Stripe: the PaymentIntent or charge is successful.
* WooCommerce: the corresponding order is not recorded as paid.

The cause is not established until the evidence is reviewed.

## Evidence to collect before changing the order

1. Confirm the WooCommerce order number and creation time.
2. Copy the Stripe PaymentIntent ID (`pi_...`) or charge ID (`ch_...`) from the order metadata or transaction details.
3. Verify that the Stripe object belongs to the same amount, currency, store mode, and order.
4. Read the WooCommerce order notes for payment-complete or error messages.
5. Review the corresponding Stripe event and webhook delivery status.
6. Check WooCommerce Stripe logs and recent plugin, checkout, cache, firewall, or hosting changes.
7. Allow for normal asynchronous processing before treating a brief delay as an incident.

Do not use a screenshot or customer statement as the only proof that the two records match.

## Where Payment Truth helps

[Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/) scans recent orders created by the official WooCommerce Stripe Gateway. When a usable PaymentIntent or charge reference exists, it reads the matching Stripe record through the gateway's existing connection and compares it with WooCommerce.

After a five-minute grace period, it can report the critical pattern “Stripe succeeded while WooCommerce still considers the order unpaid.” The finding keeps the order and provider evidence together, but it does not change the order, replay a webhook, capture money, or decide whether fulfillment is safe.

## What the plugin cannot determine by itself

A mismatch does not prove that a webhook failed. The same visible result may follow an interrupted checkout, delayed processing, an extension conflict, a manual status edit, an incorrect transaction reference, or another integration problem. Root-cause analysis still requires logs and event history.

## Official references

* [WooCommerce: Stripe extension and order statuses](https://woocommerce.com/document/stripe/admin-experience/order-statuses/)
* [WooCommerce: troubleshooting orders](https://woocommerce.com/document/managing-orders/troubleshooting-orders/)
* [WooCommerce: troubleshooting the Stripe extension](https://woocommerce.com/document/stripe/troubleshooting/)
* [Stripe: PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle)

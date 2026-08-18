---
layout: default
title: How to Reconcile WooCommerce Orders with Stripe Payments Safely
slug: reconcile-woocommerce-stripe-payments-read-only
description: A read-only workflow for comparing WooCommerce orders with Stripe status, amount, currency, and refund evidence.
updated: 2026-08-18
primary_query: reconcile WooCommerce Stripe payments
permalink: /guides/reconcile-woocommerce-stripe-payments-read-only/
---

# How to Reconcile WooCommerce Orders with Stripe Payments Safely

## Quick answer

WooCommerce–Stripe reconciliation means matching each order to its Stripe PaymentIntent or charge, then comparing payment status, gross amount, currency, and refunded total. A safe workflow identifies disagreements first and keeps state-changing actions separate from diagnosis.

## Minimum evidence for each order

Collect these fields from both systems:

| Evidence | WooCommerce | Stripe |
|---|---|---|
| Identifier | Order ID/number and transaction ID | PaymentIntent or charge ID |
| Status | Order status and paid date | PaymentIntent or charge status |
| Amount | Gross order total | Authorized or collected amount |
| Currency | Order currency | Payment currency |
| Refunds | Total refunded amount | Refund total attached to the charge |
| Timing | Creation, paid, and note timestamps | Object, event, and refund timestamps |

Do not match records only by customer name, displayed amount, or approximate time when a provider identifier is available.

## A read-only reconciliation workflow

1. Select a bounded recent order window.
2. Keep only orders created through the supported Stripe gateway.
3. Resolve each order to its stored PaymentIntent or charge ID.
4. Read the provider record without changing it.
5. Normalize amounts into the currency's smallest unit.
6. Compare status, amount, currency, and refunded total.
7. Record mismatches with timestamps and identifiers.
8. Review order notes, events, webhooks, and logs before making a correction.
9. Recheck the record after the underlying issue is resolved.

## Common mismatch patterns

* Stripe succeeded while WooCommerce still considers the order unpaid.
* WooCommerce considers the order paid while Stripe reports a terminal failure.
* Gross amounts differ.
* Currencies differ.
* Refunded totals differ.
* A pending order exceeds the expected age.
* The WooCommerce order has no usable Stripe reference.

## Automating the comparison without automating the decision

[Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/) implements this read-only comparison for stores using the official WooCommerce Stripe Gateway. It scans a configurable recent window, uses the gateway's existing Stripe connection, stores normalized findings without customer identity or card data, and can send opt-in alerts for newly opened findings.

It does not replace accounting reconciliation, repair webhooks, edit orders, capture money, or issue refunds. Those boundaries keep detection separate from financial and fulfillment decisions.

## Official references

* [WooCommerce: Stripe extension and order statuses](https://woocommerce.com/document/stripe/admin-experience/order-statuses/)
* [WooCommerce: troubleshooting orders](https://woocommerce.com/document/managing-orders/troubleshooting-orders/)
* [WooCommerce: troubleshooting the Stripe extension](https://woocommerce.com/document/stripe/troubleshooting/)
* [Stripe: PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle)

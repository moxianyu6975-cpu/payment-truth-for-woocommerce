---
layout: default
title: WooCommerce and Stripe Amount or Currency Mismatch
slug: woocommerce-stripe-amount-currency-mismatch
description: How to investigate a WooCommerce order whose gross amount or currency differs from the matching Stripe payment.
updated: 2026-08-18
primary_query: WooCommerce Stripe amount currency mismatch
permalink: /guides/woocommerce-stripe-amount-currency-mismatch/
---

# WooCommerce and Stripe Amount or Currency Mismatch

## Quick answer

When the WooCommerce order total or currency differs from the matching Stripe payment, first confirm that the transaction reference is correct. Then compare values in the currency's smallest unit and review manual edits, checkout customization, capture behavior, taxes, discounts, and timing. Do not assume that the customer was charged incorrectly from one displayed number alone.

## Start with identifiers and units

An amount comparison is meaningful only when both records refer to the same transaction and currency. Check:

1. WooCommerce order ID and Stripe PaymentIntent or charge ID.
2. Live mode versus test mode and the correct Stripe account.
3. ISO currency on both records.
4. Gross WooCommerce order total versus Stripe's authoritative authorized or collected amount.
5. Currency decimals, especially zero-decimal and three-decimal currencies.
6. Whether the order was edited after payment.

## What a mismatch does and does not prove

The mismatch proves that the normalized stored values differ at scan time. It does not prove that Stripe processed the wrong amount, that WooCommerce calculated checkout incorrectly, or that either record should be edited. Those conclusions require order history, gateway logs, and checkout context.

## Where Payment Truth helps

[Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/) normalizes the WooCommerce total into the currency's smallest unit, using the official Stripe gateway helper when available. It compares that value and the ISO currency against the matching Stripe PaymentIntent or charge.

The plugin reports amount and currency disagreements as critical findings. It never captures an adjustment, edits totals, changes currency, or modifies the order.

## Investigation checklist

* Verify the exact Stripe object reference.
* Compare order notes with Stripe timestamps.
* Review coupons, taxes, fees, shipping, manual edits, and custom checkout code.
* Check whether an authorization and later capture used the expected amount.
* Preserve evidence before correcting either system.

## Official references

* [Stripe: PaymentIntents API](https://docs.stripe.com/api/payment_intents)
* [WooCommerce: troubleshooting orders](https://woocommerce.com/document/managing-orders/troubleshooting-orders/)
* [WooCommerce: troubleshooting the Stripe extension](https://woocommerce.com/document/stripe/troubleshooting/)

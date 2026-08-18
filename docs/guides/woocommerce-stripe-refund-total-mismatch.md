---
layout: default
title: WooCommerce and Stripe Refund Totals Do Not Match
slug: woocommerce-stripe-refund-total-mismatch
description: An evidence-first workflow for a WooCommerce refunded total that differs from the amount Stripe reports as refunded.
updated: 2026-08-18
primary_query: WooCommerce Stripe refund amount mismatch
permalink: /guides/woocommerce-stripe-refund-total-mismatch/
---

# WooCommerce and Stripe Refund Totals Do Not Match

## Quick answer

A WooCommerce refund total can differ from Stripe because the two systems may have been updated at different times or through different workflows. Confirm the same payment object, currency, refund records, timing, and partial-refund history before issuing another refund or editing the order.

## Compare the same scope

Before treating the numbers as a defect, check:

* The WooCommerce order and Stripe PaymentIntent or charge are correctly linked.
* Both values use the same currency and smallest-unit interpretation.
* All partial refunds are included.
* A refund is not still pending or recently created.
* A refund was not created directly in Stripe without a corresponding WooCommerce record.
* A manual WooCommerce refund record was not created without moving money in Stripe.

WooCommerce and Stripe can each show internally consistent histories while their totals still disagree.

## Where Payment Truth helps

[Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/) reads the authoritative refunded amount available on the matching Stripe record and compares it with WooCommerce's total refunded amount. If they differ, the plugin creates a critical refund-mismatch finding.

It does not issue, reverse, or retry a refund. It also does not decide which system should be changed. Its purpose is to bring the two totals and transaction reference into one investigation queue.

## Investigation checklist

1. Export or record the WooCommerce refund line items and timestamps.
2. Review every Stripe refund attached to the exact charge.
3. Separate completed, pending, failed, and cancelled refund states where applicable.
4. Check whether a team member used the Stripe Dashboard rather than WooCommerce.
5. Review order notes and gateway logs for the original refund request.
6. Confirm the intended customer outcome before taking another financial action.

## Official references

* [Stripe: refunds](https://docs.stripe.com/refunds)
* [WooCommerce: refunds](https://woocommerce.com/document/woocommerce-refunds/)
* [WooCommerce: troubleshooting orders](https://woocommerce.com/document/managing-orders/troubleshooting-orders/)

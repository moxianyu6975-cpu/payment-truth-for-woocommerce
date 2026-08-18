---
layout: default
title: Public Evidence for WooCommerce Stripe Reconciliation
description: Public WooCommerce Stripe reports show why a separate, read-only comparison of order and provider evidence is useful.
permalink: /evidence/
---

# Why WooCommerce Stripe reconciliation matters

Public support threads and issue reports repeatedly describe the same operational risk: the Stripe record and the WooCommerce order can disagree, and the store owner may discover the problem only after reviewing logs or hearing from a customer. These reports do not establish how often the problem occurs across all stores, but they demonstrate that the failure mode is real and can have fulfillment consequences.

## Repeated public problem patterns

### Stripe succeeded while the WooCommerce order stayed pending

In [WooCommerce Stripe issue #3154](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/3154), users reported successful Stripe payments and HTTP 200 webhook responses while orders remained in Pending payment. The issue links several related WordPress.org support reports and was labeled as a confirmed bug.

The important operational distinction is that an HTTP 200 response proves that an endpoint returned successfully; it does not, by itself, prove that every downstream order update completed as intended.

### Payment attempts and order status can diverge

[WooCommerce Stripe issue #2660](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/2660) documents status-transition problems after repeated Strong Customer Authentication attempts. The public discussion includes cases where a later payment succeeded but the WooCommerce order remained failed or pending, sometimes with a different PaymentIntent reference.

This is why reconciliation should begin with exact identifiers, amounts, currencies, and timestamps rather than a status label alone.

### Current support topics continue to involve status disagreements

The official [WooCommerce Stripe Gateway support forum](https://wordpress.org/support/plugin/woocommerce-gateway-stripe/) continues to contain reports involving paid orders left pending, status transitions that do not follow payment outcomes, and amounts sent differently from the WooCommerce order. A recent example discusses an [order status that did not change to Failed after a decline](https://wordpress.org/support/topic/order-status-not-changing-to-failed-after-payment-decline/).

These reports have different causes and should not be treated as one defect. The common need is visibility: store operators need to know when the two systems disagree so they can investigate the exact transaction.

## What evidence-first reconciliation adds

A safe comparison keeps diagnosis separate from state-changing actions:

1. Select a bounded window of recent orders created through the supported Stripe gateway.
2. Resolve the exact PaymentIntent or charge reference stored on each order.
3. Read the matching Stripe record through the gateway's existing connection.
4. Normalize and compare status, gross amount, currency, and refunded total.
5. Flag stale pending orders and missing provider references.
6. Review order notes, Stripe events, webhook delivery history, and logs before changing fulfillment or financial state.

[Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/) implements that comparison as a read-only second opinion. It reports evidence and never edits orders, captures money, issues refunds, retries payments, or claims that a mismatch proves a webhook failure.

## Scope of this evidence

Public reports establish that these disagreement patterns occur; they do not provide a reliable incidence rate or prove demand for any particular plugin. Product-market fit must be measured separately through active installations, support conversations, retained usage, and reports from real store operators.

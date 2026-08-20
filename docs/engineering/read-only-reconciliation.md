---
layout: default
title: Why I Built a Read-Only Reconciliation Layer for WooCommerce Stripe Payments
description: An engineering case study about identity correlation, safe failure, and evidence-first WooCommerce Stripe reconciliation.
permalink: /engineering/read-only-reconciliation/
---

# Why I built a read-only reconciliation layer for WooCommerce Stripe payments

*Designing Payment Truth around evidence, identity, and safe failure instead of automatic repair.*

Payment integrations are usually described as a checkout problem: collect payment, receive a webhook, update the order, and continue fulfillment.

The harder problem begins when those steps disagree.

A Stripe PaymentIntent can be successful while the related WooCommerce order is still pending or otherwise unpaid. The reverse can also happen: WooCommerce can show a paid order while the provider record reports a terminal failure. Refund totals, currencies, and gross amounts can drift as well.

At that point, automatically changing the order is not the safest first action. The first task is to establish what the two systems actually say about the same transaction.

That observation became the design principle behind [Payment Truth for WooCommerce](https://wordpress.org/plugins/payment-truth-for-woocommerce/), the open-source plugin I built and released on WordPress.org.

## Reconciliation is an identity problem before it is a status problem

Seeing “succeeded” in Stripe and “pending” in WooCommerce is not enough evidence to act. Before comparing statuses, the software must establish that the provider object belongs to the exact order being investigated.

Payment Truth starts with the PaymentIntent or charge reference stored by the official WooCommerce Stripe Gateway. It then reads the matching Stripe object through the gateway connection already configured inside the store.

Only after that correlation does it compare normalized evidence:

- WooCommerce payment state versus Stripe payment state;
- gross amount;
- currency;
- refunded total when Stripe provides authoritative refund data;
- how long a provider payment has remained pending; and
- whether a supported Stripe order has a usable provider reference.

This order of operations matters. A correct status attached to the wrong payment is still incorrect evidence.

## Why the plugin does not repair orders automatically

It is tempting to turn every detected mismatch into an automatic fix. For a payment tool, that can create a second financial failure while trying to resolve the first one.

A visible disagreement does not prove its root cause. It may result from delayed asynchronous processing, an interrupted checkout, a webhook delivery problem, a plugin conflict, a manual status edit, an incorrect transaction reference, or another integration failure.

For that reason, I gave the plugin a strict safety boundary:

- it never captures or refunds money;
- it never retries a payment;
- it never changes a WooCommerce order;
- it never replays a webhook; and
- it never asks the merchant to paste another Stripe secret key.

The output is an evidence queue for a human investigation, not an instruction to fulfill, refund, or edit an order.

## Reusing the existing gateway connection

I did not want reconciliation to require merchants to send WooCommerce credentials and a new Stripe key to a separate scanning service.

Payment Truth runs inside WordPress and reuses the official Stripe gateway's existing connection. This keeps the workflow inside the environment the merchant already operates and reduces the number of credentials introduced by the diagnostic tool.

The plugin also avoids storing customer identity or card data in its findings. It keeps normalized payment evidence needed for comparison rather than building another customer-data store.

## Reducing noise is part of correctness

Distributed payment systems are asynchronous, so a brief disagreement can be normal. Reporting every momentary difference immediately would create false alarms and train operators to ignore the results.

The current release therefore waits five minutes before reporting status disagreements. Scans are also bounded by a configurable lookback window and maximum order count, whether they run manually or on the hourly schedule.

When Stripe cannot be read reliably, the scanner reports provider-read health, account or live/test mode problems, and the next investigation step instead of presenting an uncertain comparison as a confirmed mismatch.

Failing safely includes being explicit about what the software could not verify.

## Working with WooCommerce as a moving platform

WooCommerce stores do not all use the same storage implementation. To remain compatible with High-Performance Order Storage (HPOS), Payment Truth reads orders through WooCommerce CRUD APIs instead of querying legacy order tables directly.

Version 0.2.0 supports the official WooCommerce Stripe Gateway and payment-method IDs equal to `stripe` or beginning with `stripe_`. I deliberately document that boundary because pretending to support every third-party Stripe extension would make the results less trustworthy.

The project currently includes standalone regression checks, package checks, and PHP syntax validation in GitHub Actions across supported PHP versions. Shipping through WordPress.org also required packaging, guideline review, SVN releases, public documentation, and a support path—not only writing the scanner.

## What this project taught me

The most useful engineering lesson was that a financial diagnostic tool should make uncertainty visible rather than hide it behind automation.

The project brought together several areas that are easy to treat separately:

- WooCommerce order models and HPOS compatibility;
- Stripe PaymentIntent and charge evidence;
- normalization across two state systems;
- defensive handling of partial provider failures;
- privacy-conscious persistence;
- scheduled and manual background work;
- WordPress plugin distribution and maintenance; and
- automated checks for a codebase that must support older PHP environments.

It also reinforced a product lesson: the safety boundary is not a limitation to apologize for. For this problem, read-only behavior is the feature that makes the first layer useful.

## Current status

Payment Truth for WooCommerce 0.2.0 is free and open source:

- [Install it from WordPress.org](https://wordpress.org/plugins/payment-truth-for-woocommerce/)
- [Read the source code on GitHub](https://github.com/moxianyu6975-cpu/payment-truth-for-woocommerce)
- [Review the evidence-first guides](https://moxianyu6975-cpu.github.io/payment-truth-for-woocommerce/)

I am continuing to validate the workflow with authorised WooCommerce store operators and developers. If you have encountered a reproducible WooCommerce/Stripe disagreement, I would be interested in the anonymized sequence of events and the evidence you needed before acting.

*Disclosure: I am the author of Payment Truth for WooCommerce. It is currently free and has no paid tier.*

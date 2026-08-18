---
layout: default
title: Payment Truth for WooCommerce FAQ
description: Answers about read-only WooCommerce Stripe reconciliation, supported gateways, mismatches, safety, privacy, and alerts.
permalink: /faq/
---

# Frequently asked questions

## What problem does Payment Truth solve?

It finds recent WooCommerce orders whose stored payment evidence disagrees with the matching Stripe PaymentIntent or charge. The initial release checks status, gross amount, currency, refunded total, stale pending orders, and missing provider references.

## Does a mismatch prove that a Stripe webhook failed?

No. A finding proves only that the WooCommerce and Stripe records disagreed when scanned. Delayed webhooks, interrupted checkout, manual edits, retries, plugin conflicts, or an incorrect reference can produce similar evidence.

## Does the plugin change orders or move money?

No. Version 0.1.0 is deliberately read-only. It never captures, refunds, retries, replays webhooks, or changes an order status.

## Does it need my Stripe secret key?

No. Payment Truth uses the authentication already configured by the official WooCommerce Stripe Gateway and does not store Stripe credentials.

## Which Stripe plugin is supported?

The initial release supports the official WooCommerce Stripe Gateway and payment method IDs equal to `stripe` or beginning with `stripe_`. Other Stripe extensions may store different references and are not queried.

## Can WooCommerce and Stripe amounts really differ?

The values stored in the two systems can disagree after manual edits, unusual capture flows, interrupted integrations, or other data drift. Payment Truth normalizes the WooCommerce total into the currency's smallest unit and compares it with authoritative Stripe evidence. A finding asks you to investigate; it does not claim Stripe processed the wrong amount.

## Does it store customer or card data?

The findings table stores normalized reconciliation evidence such as order ID, provider object ID, statuses, amounts, currency, issue type, and timestamps. It does not store customer names, addresses, email addresses, card data, or Stripe credentials.

## Why is a status mismatch not shown immediately?

Payment updates are asynchronous. Payment Truth waits five minutes before reporting status disagreements and scans hourly by default. Administrators can also run a bounded manual scan.

## Where can I install it or ask for help?

Install it from the [WordPress.org plugin directory](https://wordpress.org/plugins/payment-truth-for-woocommerce/) and use the [official support forum](https://wordpress.org/support/plugin/payment-truth-for-woocommerce/) for setup and usage questions.

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "What problem does Payment Truth for WooCommerce solve?",
      "acceptedAnswer": {"@type": "Answer", "text": "It finds recent WooCommerce orders whose stored payment evidence disagrees with the matching Stripe PaymentIntent or charge, including status, amount, currency, refund, stale-pending, and missing-reference findings."}
    },
    {
      "@type": "Question",
      "name": "Does a mismatch prove that a Stripe webhook failed?",
      "acceptedAnswer": {"@type": "Answer", "text": "No. A finding proves only that the WooCommerce and Stripe records disagreed when scanned. Root-cause analysis still requires order notes, events, webhook delivery history, and logs."}
    },
    {
      "@type": "Question",
      "name": "Does Payment Truth change orders or move money?",
      "acceptedAnswer": {"@type": "Answer", "text": "No. It is read-only and never captures, refunds, retries, replays webhooks, or changes an order status."}
    },
    {
      "@type": "Question",
      "name": "Does Payment Truth need another Stripe secret key?",
      "acceptedAnswer": {"@type": "Answer", "text": "No. It uses the authentication already configured by the official WooCommerce Stripe Gateway and does not store Stripe credentials."}
    },
    {
      "@type": "Question",
      "name": "Which Stripe plugin does Payment Truth support?",
      "acceptedAnswer": {"@type": "Answer", "text": "Version 0.1.0 supports the official WooCommerce Stripe Gateway and payment method IDs equal to stripe or beginning with stripe_."}
    }
  ]
}
</script>

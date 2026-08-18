# Contributing

Thanks for helping improve Payment Truth for WooCommerce.

## Before opening an issue

- Confirm the store uses the official WooCommerce Stripe Gateway.
- Reproduce the problem on current supported versions when possible.
- Remove customer names, addresses, email addresses, card data, API keys, webhook URLs, and other secrets from screenshots or logs.
- Use the [WordPress.org support forum](https://wordpress.org/support/plugin/payment-truth-for-woocommerce/) for setup questions.
- Use GitHub Issues for reproducible bugs and focused feature requests.

## Development principles

- Preserve the plugin's read-only boundary: reconciliation must not capture, refund, retry, replay webhooks, or change order status.
- Keep scans bounded and compatible with WooCommerce CRUD/HPOS APIs.
- Treat a mismatch as evidence, not proof of a root cause.
- Avoid storing customer identity, card data, or Stripe credentials.
- Keep WordPress.org directory requirements and internationalization in mind.

## Checks

Run these before submitting code:

```bash
php tests/test-core.php
php tests/test-package.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Describe the behavior changed, why it changed, and how you verified it. Security vulnerabilities must follow [SECURITY.md](SECURITY.md) instead of a public issue.

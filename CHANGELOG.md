# Changelog

## 1.0.1 - 2026-10-06

- Match the actual invoice-detail API, which does not include callback-only
  requires_review. Derive exceptions from its verified status, amount and timing.
- Accept normal invoice creation/settlement responses without that flag; retain
  signature, scope, amount, replay and duplicate-fulfillment protections.
- Overpayments remain a manual-review case. TLS fixtures now use the real API shape.
- Supersedes 1.0.0; use this release for new installations.

## 1.0.0 - 2026-10-06

- First hosted-checkout payment integration with an included, isolated PHP SDK.
- Native configuration and payment/order updates, signed IPN plus API verification.
- Saved idempotency keys, exact fiat matching, duplicate protection and review paths.
- Connection checks, setup guide, synthetic TLS and native-platform integration tests.

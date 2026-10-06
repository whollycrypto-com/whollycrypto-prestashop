# Verification

`python3 tests/package.py` checks SDK provenance, byte-for-byte reproducibility,
the release allowlist and an extracted package's autoloader. The installer and
payment path also refuse non-InnoDB order storage or unsafe fulfillment statuses.

Run protocol tests with PHP 8.1 or newer and `python3 tests/https-flow.py` with cURL
and OpenSSL. TLS fixtures bind localhost, create their own disposable certificate
and synthetic invoices, and never connect to a real Wholly server.

`tests/repository.php` additionally needs `WHOLLY_TEST_DB_DSN`, optional
`WHOLLY_TEST_DB_USER`/`WHOLLY_TEST_DB_PASSWORD`, and PDO MySQL. Use a disposable DB.
It creates/removes a random test table and checks locks, rollback and uniqueness.

For native-platform tests, install PrestaShop 9.2 in an isolated environment with
domain `shop.example.test` and this module at `modules/whollycrypto`. Set
`WHOLLY_TEST_DISPOSABLE=yes`, `WHOLLY_TEST_PRESTASHOP_ROOT`, `WHOLLY_TEST_FLOW=platform.php`
and run the TLS script. `PHP_BINARY` can select a PHP executable with the right
extensions and database socket. This creates synthetic customers, products, carts
and orders. It disables fixture mail. **Never point it at a production shop.**

Release verification covers native installation, payment-option discovery, settings
rendering, real TLS requests, persisted retries, partial/settled/duplicate/old events,
expiry, changed totals, reorg notes and exact native payment count. It does not
certify every theme, carrier, checkout customization or a real blockchain payment.

## Before enabling your shop

1. Back up and install on staging. Configure a separate Wholly test project/store.
2. Test guest and registered checkout, physical shipping, tax, discounts and each
   enabled fiat currency. Split shipments are not supported by this release.
3. Confirm the customer sees the correct final fiat total and allowed assets.
4. Complete a small payment in your own controlled test, observing confirmations,
   signed IPN and exactly one native payment/order update.
5. Resend the same notification and an older event; no second fulfillment.
6. Test expiry, partial payment, invalid secret, API outage and manual recovery.
7. Confirm a browser return and a forged callback cannot mark an order paid.
8. Test status-driven stock, email and fulfillment extensions before going live.

# Wholly Crypto for PrestaShop

Accept crypto in your PrestaShop shop through **your own Wholly Crypto installation**.
Customers choose a supported network and asset on your hosted checkout. Your shop
receives verified order updates; no wallet keys belong in this module.

[Download install ZIP](https://github.com/whollycrypto-com/whollycrypto-prestashop/releases/latest) · [Setup guide](https://www.whollycrypto.com/plugins/prestashop/) · [Wholly Crypto](https://www.whollycrypto.com/)

## Requirements

- PrestaShop **9.x**; native integration tests run on **9.2.0**. This first release
  is not for PrestaShop 1.7 or 8.x. Test your exact theme and checkout on staging.
- PHP **8.1+**, cURL, JSON, valid CA certificates and an InnoDB shop database.
- Current Wholly Crypto merchant installation (API tested against the 7.8 contract),
  with an enabled project/store, accepted payment methods and healthy receiving setup.
- HTTPS on the shop, Wholly API and Wholly checkout. Both servers need outbound
  HTTPS; Wholly must reach the shop's callback without login, CAPTCHA or Basic Auth.

## Install

1. Back up your shop. Download **`whollycrypto-prestashop-1.0.0.zip`** from Releases,
   not GitHub's automatic source archive. Verify it with the attached `SHA256SUMS`.
2. In **Modules → Module Manager → Upload a module**, upload the ZIP. It contains
   the required `whollycrypto/` module directory and its isolated PHP SDK.
3. Open **Wholly Crypto → Configure**. For multishop, select one specific shop first.
4. Enter your API origin (`https://api.example.com`) and checkout origin
   (`https://pay.example.com`), without paths. The checkout origin must match the
   default checkout domain returned for that Wholly store.
5. Copy **Project API UUID** and **Store API UUID** from Wholly **Project → Stores →
   Basic → API IDs**. Create a read/write API credential restricted to that project.
6. In Wholly **Store → IPN**, enable IPN and create/copy the signing secret. Enter
   that same secret in the module. The module supplies an invoice-specific IPN URL.
   You do not need to configure an additional webhook.
7. Save, then **Test saved connection**. Check PrestaShop's payment restrictions
   for countries, currencies and customer groups. Complete the staging checklist.

The settings form never displays saved secrets. Leaving a secret field blank keeps
its current value. Optional sharing of the customer's email is off by default.

## Payment flow

1. The customer selects **Pay with crypto** and confirms the order.
2. PrestaShop creates an **Awaiting Wholly Crypto payment** order. The module saves
   the exact fiat amount, currency, order reference and idempotency key before any
   remote invoice request. A timed-out request reuses the same key and payload.
3. The customer pays at your Wholly checkout. Returning to the shop is navigation,
   **never proof of payment**.
4. The module verifies the raw IPN HMAC signature and scope, then reads the invoice
   through the authenticated API. Only a matching, settled, non-review payment
   marks the order paid. Repeated or out-of-order events cannot add a second payment.

| Wholly invoice | Shop handling |
| --- | --- |
| `new` or `processing` | Awaiting payment, including partial or unconfirmed funds |
| `settled`, paid/overpaid, no review or late flag | Native payment record and Payment accepted; only the original fiat total is credited |
| `expired` / `cancelled`, no funds | Cancel pending order |
| Invalid, late, ambiguous, changed total or cancelled order receiving funds | Review; never automatically fulfill or revive an order |
| Payment changes after fulfillment | Private review note; no automatic refund or reversal |

`event_type` describes what happened; `status` describes the invoice snapshot.
Two different events can carry the same status. The module trusts neither alone:
it verifies the signature, saved identity and current API state before settlement.
See [IPN & webhook documentation](https://www.whollycrypto.com/documentation/#delivery-history).

## Recovery and updates

- **Modules → Wholly Crypto → Recover an order** checks an existing invoice by its
  PrestaShop order ID. Order details also show the linked Wholly invoice ID.
- In Wholly, inspect **Store → IPN → History → Details** and resend a failed delivery.
  Invalid signatures return 401; temporary verification/storage errors return 503
  so the sender retries. Keep server clocks synchronized.
- After uncertain creation, retry the same order. Do not manually create a second
  invoice. Do not change the API credential until that creation is reconciled.
- Low Wholly processing credits may pause IPN even though customers can still pay.
  Restore credits and reconcile delivery history. Do not assume pending means unpaid.
- Reconcile all open payments before changing the connected store or origins,
  uninstalling, rotating the IPN secret or taking the shop offline.
- Upload future release ZIPs through the same module workflow. Wholly merchant
  updates do not update this module. Records, settings and custom order states are
  retained on module uninstall; protect your shop backups like API credentials.

## Scope

One positive fiat-priced order per cart; customers choose among the Wholly store's
accepted assets. Split-shipment/multi-order carts are rejected before payment.
This release does not add refunds, automatic recurring debits, token pricing,
checkout iframes or native blockchain scanning. Handle refunds in your normal
reviewed workflow. Third-party fulfillment hooks remain your responsibility.

This independent module is not a PrestaShop endorsement or Marketplace listing.
The module adds no percentage fee; your Wholly processing-credit policy still applies.

## Development

MIT licensed, including the separately attributed, namespace-isolated public PHP
SDK. No Composer install is needed. See [tests and staging checklist](docs/testing.md),
[security](SECURITY.md) and [changelog](CHANGELOG.md).

```sh
php tests/protocol.php
python3 tests/https-flow.py
python3 tools/build.py
```

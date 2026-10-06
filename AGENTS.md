# Public PrestaShop integration

Only this module, its tests and bundled public MIT PHP SDK belong here. No server
implementation, credentials, customer exports or private deployment helpers.
Read README.md and docs/testing.md. Check Git status before editing.
Keep amount/identity checks, saved idempotency keys, signed callbacks and native
order-payment deduplication intact. Browser returns must never fulfill orders.
Build installable ZIPs with tools/build.py, not a source-tree archive. Test locally
with synthetic fixtures. Publication is a separate, explicitly authorized step.

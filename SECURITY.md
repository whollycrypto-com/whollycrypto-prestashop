# Security

Do not post API credentials, IPN secrets, wallet keys, customer payloads or shop
backups in public issues. Report security problems through the private contact
form at https://www.whollycrypto.com/contact/ without sensitive data first.

Use HTTPS, a project-restricted API credential and a unique store IPN secret.
Protect the shop database and its backups; API credentials are stored there using
the platform's configuration storage. This plugin never needs wallet keys.
Never disable signature, amount, identity or checkout-origin verification.

Keep server clocks synchronized and callback routes reachable without CAPTCHA,
Basic Auth or browser login. Keep ordinary administration authentication enabled.
Review unexpected or late payments manually. Updates and backups are your
responsibility; see the staging checklist before accepting real payments.

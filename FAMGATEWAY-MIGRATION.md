# FamGateway migration — deployment checklist

This repository contains a FamGateway Add Funds UI and PHP endpoints. **The integration is not live-ready until private server credentials are configured and the gateway/Firebase flows pass staging tests.** A GitHub commit alone does not deploy files to InfinityFree; upload/sync the updated repository files to the hosting account separately.

## Current files
- `addfunds.html`: Add Funds checkout UI. The merchant API key must stay server-side.
- `famgateway-create-order.php`: validates a Firebase ID token, creates an order, and stores the intended user and amount server-side.
- `famgateway-verify-payment.php`: verifies the authenticated user, order ownership, payment status, transaction reference, and stored amount before requesting wallet credit.
- `famgateway-webhook.php`: validates a signature, independently verifies the order with FamGateway, and requests the same idempotent wallet-credit helper.
- `famgateway-lib.php`: shared Firebase REST and ETag compare-and-set wallet helper.
- `famgateway.config.example.php`: safe template only; it contains no live secrets.
- `check_payment.php`: legacy verification path removed because it trusted client-controlled payment details.

## Firebase browser configuration
`firebase.js` has been restored with the panel's existing Firebase web configuration for project `smm-panel-86035`. The existing `index.html` was intentionally left unchanged. Firebase web configuration is designed to be used in the browser; **never put Firebase service-account JSON, private keys, or Admin SDK credentials in `firebase.js`, `index.html`, or any public GitHub file.**

The ImgBB key used by browser uploads is also visible to visitors when embedded in frontend JavaScript. If it is abused, rotate it in ImgBB.

## Private server configuration
Create `famgateway.config.php` on the PHP server from the example template, or configure the listed environment variables if the host supports them. Keep the real file out of Git. The PHP endpoints require these values:

- `FAMGATEWAY_API_KEY`: private merchant API key from the FamGateway dashboard.
- `FAMGATEWAY_WEBHOOK_SECRET`: only the actual signing secret and signature format confirmed by FamGateway. Do not guess this value.
- `FIREBASE_WEB_API_KEY`: Firebase web API key used by the server to validate ID tokens. This is not a service-account private key.
- `FIREBASE_DATABASE_URL`: the Realtime Database URL for `smm-panel-86035`.
- `FIREBASE_ADMIN_TOKEN`: a server-side credential with the required Realtime Database access. A normal Firebase web API key is **not** an Admin token. Do not paste service-account JSON into this variable.
- `PANEL_BASE_URL`: the canonical HTTPS origin for the deployed panel.

Do not send merchant keys, Firebase Admin credentials, or service-account private keys in chat, commit them to GitHub, or put them in browser JavaScript. If a service-account private key was previously shared or committed, revoke that key and create a replacement.

## Webhook setup
After the PHP files and private server configuration are installed on a host that supports public incoming HTTPS webhooks, configure the FamGateway webhook URL as:

`https://YOUR-DOMAIN.example/famgateway-webhook.php`

InfinityFree may impose restrictions on PHP outbound requests, environment variables, or incoming webhook requests. Do not assume this flow works there until it is tested end-to-end. If the host blocks required requests or cannot keep secrets private, use a PHP host/serverless backend that supports secure secrets and webhooks.

## Staging checklist before live deposits
1. Confirm the official FamGateway order-creation endpoint, verification response fields, event names, signature header, and HMAC secret/format from current merchant documentation.
2. Configure all required private values on the server; keep `famgateway.config.php` out of the repository.
3. Confirm the database's actual wallet field and security rules match the implementation's assumptions.
4. Run `php -l` on every PHP endpoint.
5. Test pending, success, failed/expired, wrong-user, amount-mismatch, forged-signature, duplicate verification, duplicate webhook, and provider-timeout cases.
6. Confirm retries never credit the same order twice and one transaction reference cannot fund multiple orders.
7. Test wallet credit and order status recovery after partial failures.
8. Only enable real deposits after successful staging tests and a reconciliation plan.

## Security notes
- Rotate any real Telegram bot token, database secret, provider API key, or other credential previously committed to public Git history. Removing a secret in a new commit does not erase old history.
- Never disable TLS certificate verification.
- The payment success/return page is not proof of payment; only server-side gateway verification can authorize a wallet credit.

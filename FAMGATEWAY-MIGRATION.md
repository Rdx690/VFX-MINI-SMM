# FamGateway migration — deployment checklist

This branch introduces a new FamGateway-based Add Funds UI and server endpoints. **Do not merge or enable live deposits until the required server credentials and atomic balance ledger are configured.**

## What changed
- `addfunds.html`: replaced the old static personal-UPI QR generator with a FamGateway order/checkout flow. The merchant key is not exposed to browser JavaScript.
- `famgateway-create-order.php`: verifies the logged-in Firebase user ID token, creates a FamGateway order, and stores the order's intended user and amount server-side.
- `famgateway-verify-payment.php`: verifies order ownership and calls the gateway's authenticated server-to-server verification endpoint. It fails closed before credit until an atomic ledger adapter is supplied.
- `famgateway-webhook.php`: validates the HMAC-SHA256 signature over the raw request body and checks the stored order/amount. It records authenticated webhook events and fails closed before credit until atomic ledger support is present.
- `famgateway.config.example.php`: environment configuration template.
- `check_payment.php`: disabled the legacy verification path because it accepted client-controlled amount/user identifiers and used obsolete gateway verification.

## Required deployment configuration
Create a server-only `famgateway.config.php` from the example template, and ensure it is excluded from Git. Configure the following in the PHP hosting environment:
- `FAMGATEWAY_API_KEY`: live merchant API key from the FamGateway dashboard.
- `FIREBASE_WEB_API_KEY`: Firebase web API key used only server-side here to validate ID tokens. It is not a private service credential.
- `FIREBASE_DATABASE_URL`: Realtime Database URL.
- `FIREBASE_ADMIN_TOKEN`: a server-side Firebase Admin REST credential/token with least privilege, or replace REST calls with the Firebase Admin SDK. Do not use a client-side database secret.
- `FAMGATEWAY_WEBHOOK_SECRET`: optional; if not set, webhook verification uses `FAMGATEWAY_API_KEY` per the published FamGateway documentation.

Configure the FamGateway webhook URL in the merchant dashboard as:
`https://YOUR-DOMAIN.example/famgateway-webhook.php`

## Important unfinished production gate
No automatic wallet balance credit has been enabled in this branch. Firebase Realtime Database REST GET/PATCH is not an atomic, idempotent ledger for money movements. Implement a trusted Firebase Admin SDK transaction or callable Cloud Function that:
1. takes an order ID and UTR;
2. checks the stored order belongs to the user and exact amount matches;
3. rejects any previously credited order ID or UTR;
4. atomically writes the ledger entry, marks the order completed, and updates the user's balance in the same transaction;
5. is callable only by this verified backend/webhook path;
6. never accepts an amount or UID directly from the browser as authority.

## Other security notes
- The currently public repo had hard-coded Telegram bot token, database secret and external provider keys. Rotate all real credentials immediately and move them to secret environment variables. Removing a key from a future commit does not erase it from Git history.
- Do not disable TLS verification.
- The old success page takes amount/order/user from URL parameters and should not be used as authoritative proof of payment. The UI in this branch no longer redirects to it.
- Verify webhook event names and exact response fields against a merchant test order before production use.
- Run `php -l` on each PHP file and test a pending order, successful order, forged signature, amount mismatch, duplicate webhook, wrong-user request, expired order, and provider timeout before enabling deposits.

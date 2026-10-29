# FamGateway migration — deployment checklist

This branch introduces a new FamGateway-based Add Funds UI and server endpoints. **Do not merge or enable live deposits until the required server credentials and atomic balance ledger are configured.**

## What changed
- `addfunds.html`: replaced the old static personal-UPI QR generator with a FamGateway order/checkout flow. The merchant key is not exposed to browser JavaScript.
- `famgateway-create-order.php`: verifies the logged-in Firebase user ID token, creates a FamGateway order, and stores the order's intended user and amount server-side.
- `famgateway-verify-payment.php`: verifies Firebase identity, order ownership, gateway payment status, transaction reference, and the exact stored amount before calling the shared idempotent wallet-credit helper.
- `famgateway-webhook.php`: validates the HMAC-SHA256 signature over the raw body, independently verifies the order with FamGateway, checks the stored order/amount, then invokes the same idempotent wallet-credit helper.
- `famgateway.config.example.php`: environment configuration template, including `PANEL_BASE_URL` for a canonical HTTPS webhook URL.
- `famgateway-lib.php`: shared Firebase REST and ETag compare-and-set helper. The wallet balance and per-order credit ledger are saved in the same `users/{uid}` node so concurrent retries cannot credit the same order twice.
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

## Production verification still required

The implementation now attempts idempotent credit by using Firebase Realtime Database ETags to compare-and-set the user's full record. This is safer than a normal read-then-write, but it is **not yet production-certified** and depends on the current data model storing the numeric wallet at `users/{uid}/balance`. Validate that the existing Firebase rules and data shape match this assumption.

Before accepting live payments:
1. Configure the FamGateway API key, webhook signing secret, Firebase server credential, Firebase web API key, and `PANEL_BASE_URL` as server-side environment variables.
2. Confirm FamGateway's exact verify-order response fields and webhook signature/event schema using a merchant test order.
3. Run `php -l` on every PHP endpoint and test pending/success/expired orders, wrong-user requests, amount mismatch, forged signatures, duplicate verify calls, duplicate webhook delivery, and Firebase ETag conflicts.
4. Confirm wallet balance and `famgatewayCredits/{orderId}` update together on the same user record; verify a repeated callback never increases the balance twice.
5. Configure a monitored recovery/reconciliation path for cases where the wallet credit succeeds but the subsequent order-status write fails. Retrying the same order should repair the status without adding the balance again.
6. Do not merge or deploy until the above checks pass on staging.

## Other security notes
- The currently public repo had hard-coded Telegram bot token, database secret and external provider keys. Rotate all real credentials immediately and move them to secret environment variables. Removing a key from a future commit does not erase it from Git history.
- Do not disable TLS verification.
- The old success page accepted query-string payment details and included a hard-coded Telegram bot credential. It has been replaced with a non-authoritative return screen. Rotate any real Telegram bot token previously committed; removing it from the latest file does not remove it from Git history.
- Verify webhook event names and exact response fields against a merchant test order before production use.
- Run `php -l` on each PHP file and test a pending order, successful order, forged signature, amount mismatch, duplicate webhook, wrong-user request, expired order, and provider timeout before enabling deposits.

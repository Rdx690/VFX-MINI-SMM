# VFX MINI SMM — FamGateway rollout and security checklist

## Current behavior
- `addfunds.html` now uses a responsive iOS-inspired white liquid-glass interface and retains the FamGateway create-order, QR/hosted checkout, polling, and server-verification calls.
- `check_payment.php` is intentionally disabled (HTTP 410). Do not restore the legacy flow: its prior implementation trusted client-supplied identity/amount and obsolete gateway status.
- `success.php` is now display-only. URL query parameters are untrusted and cannot prove payment, credit a wallet, or trigger a Telegram payment-success notification.
- `failed.php` is display-only and does not assert a final gateway state based only on a redirect.
- `famgateway-create-order.php` and `famgateway-verify-payment.php` are server endpoints and require live configuration plus testing.
- `famgateway-webhook.php` currently validates signatures and records events but intentionally returns `needs_atomic_ledger` without crediting the wallet. Webhook auto-credit is **not complete**.

## Server configuration
Create an untracked `famgateway.config.php` from `famgateway.config.example.php`. Configure server-side environment variables:
- `FAMGATEWAY_API_KEY` — live merchant API key
- `FAMGATEWAY_WEBHOOK_SECRET` — webhook signing secret, if provided separately by FamGateway
- `FIREBASE_WEB_API_KEY` — Firebase web API key used for Identity Toolkit token lookup
- `FIREBASE_DATABASE_URL` — Realtime Database URL
- `FIREBASE_ADMIN_TOKEN` — privileged server-side credential; prefer Firebase Admin SDK / service account over legacy database secrets

Configure the webhook URL in FamGateway as `https://YOUR-DOMAIN.example/famgateway-webhook.php`. Never put merchant keys or privileged Firebase credentials in browser JavaScript or Git.

## Production gate — do not accept live deposits until all pass
1. Review the actual FamGateway merchant response schema using a test order; confirm the exact success status, paid amount, order ID, and UTR fields.
2. Implement a trusted atomic ledger (prefer Firebase Admin SDK transaction or Cloud Function) that checks order ownership, exact amount, unique order ID and unique UTR, then atomically writes the credit ledger, order completion and wallet balance.
3. Route both browser verification and webhook events through the same idempotent ledger function. The webhook currently does not credit funds.
4. Run `php -l` on every PHP endpoint and test pending/success/expired states, forged signature, amount mismatch, duplicate order/webhook, duplicate UTR, wrong-user request, and provider timeouts.
5. Configure server secrets only on the PHP host; the repository alone is not a deployment.

## Credential exposure
A previous version of `success.php` contained a hard-coded Telegram bot token and disabled TLS certificate verification while attempting fallback IP addresses. Treat that token as compromised: revoke/regenerate it in BotFather, check bot activity, and keep the replacement only in server environment variables. Public repository history may still contain old values; removing them in a new commit does not erase history. Rotate any other real secrets found in Git history and review Firebase Database Rules.

## Important
A successful redirect, a QR image, a public status response, or a client-side balance display is not sufficient proof of payment. Do not report the payment integration as production-ready until the ledger is atomic, credentials are configured, and live test payments pass.

<?php
declare(strict_types=1);

/**
 * LEGACY VC STORE GATEWAY ENDPOINT — DISABLED.
 *
 * The previous implementation called the old VC Store payment API and used client-provided
 * order/user/amount values before updating users/{uid}/balance. That flow is obsolete and must
 * not be used to confirm or credit a FamGateway payment.
 *
 * Keep this route as a hard stop so cached/old clients cannot accidentally use the old flow.
 * Current flow:
 *   famgateway-create-order.php -> FamGateway checkout/QR
 *   famgateway-verify-payment.php -> authenticated gateway verification + idempotent wallet credit
 *   famgateway-webhook.php -> signed webhook + independent gateway verification + idempotent credit
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
http_response_code(410);
echo json_encode([
    'status' => 'disabled',
    'message' => 'The old payment route is disabled. Reload Add Funds and create a new FamGateway order.'
], JSON_UNESCAPED_SLASHES);

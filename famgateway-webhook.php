<?php
declare(strict_types=1);

$config = require __DIR__ . '/famgateway.config.php';
require_once __DIR__ . '/famgateway-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function gatewayVerify(array $config, string $orderId): array {
    $key = (string)($config['api_key'] ?? '');
    if ($key === '') throw new RuntimeException('FamGateway API key is not configured');
    $url = 'https://famgateway.in/api/verify-order.php?order_id=' . rawurlencode($orderId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Api-Key: ' . $key],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) throw new RuntimeException('Gateway verification failed: ' . $code . ' ' . $error);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException('Gateway returned invalid verification JSON');
    return $decoded;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fg_reply(405, ['status'=>'error','message'=>'POST required']);
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) fg_reply(400, ['status'=>'error','message'=>'Empty or oversized webhook payload']);

    $signature = trim((string)($_SERVER['HTTP_X_FAMGATEWAY_SIGNATURE'] ?? ''));
    $secret = (string)($config['webhook_secret'] ?? '');
    if ($secret === '' || !preg_match('/^[a-f0-9]{64}$/i', $signature)) fg_reply(401, ['status'=>'error','message'=>'Missing or malformed signature']);
    $expectedSignature = hash_hmac('sha256', $raw, $secret);
    if (!hash_equals($expectedSignature, strtolower($signature))) fg_reply(401, ['status'=>'error','message'=>'Invalid webhook signature']);

    $event = json_decode($raw, true);
    if (!is_array($event)) fg_reply(400, ['status'=>'error','message'=>'Malformed webhook JSON']);
    $payload = is_array($event['data'] ?? null) ? array_merge($event, $event['data']) : $event;
    if (($payload['event'] ?? '') !== 'payment.success' || strtolower((string)($payload['status'] ?? '')) !== 'success') {
        fg_reply(200, ['status'=>'ignored','message'=>'Event is not a successful payment']);
    }

    $orderId = (string)($payload['order_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $orderId)) fg_reply(422, ['status'=>'error','message'=>'Invalid order ID']);
    $eventAmount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($eventAmount === false || !is_finite((float)$eventAmount) || (float)$eventAmount <= 0) fg_reply(422, ['status'=>'error','message'=>'Invalid webhook amount']);

    $intent = fg_firebase($config, 'famgateway_orders/' . rawurlencode($orderId));
    if (!is_array($intent) || empty($intent['uid']) || !isset($intent['amount'])) fg_reply(404, ['status'=>'error','message'=>'Unknown payment order']);
    if (($intent['status'] ?? '') === 'completed') fg_reply(200, ['status'=>'success','message'=>'Order already credited','order_id'=>$orderId]);
    $expectedAmount = (float)$intent['amount'];
    if (abs((float)$eventAmount - $expectedAmount) > 0.001) fg_reply(409, ['status'=>'error','message'=>'Webhook amount does not match stored order']);

    // Never rely on webhook payload alone: independently verify the order using the merchant API key.
    $verification = gatewayVerify($config, $orderId);
    $verified = is_array($verification['data'] ?? null) ? $verification['data'] : $verification;
    $gatewayStatus = strtolower((string)($verified['status'] ?? $verification['status'] ?? ''));
    if (!in_array($gatewayStatus, ['success','paid','completed'], true)) {
        fg_reply(202, ['status'=>'pending','message'=>'Gateway has not independently confirmed this order']);
    }
    $verifiedAmount = filter_var($verified['amount'] ?? $verified['payable_amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($verifiedAmount === false || abs((float)$verifiedAmount - $expectedAmount) > 0.001) {
        fg_reply(409, ['status'=>'error','message'=>'Verified gateway amount does not match stored order']);
    }
    $utr = trim((string)($verified['utr'] ?? $verified['transaction_id'] ?? $payload['utr'] ?? $payload['transaction_id'] ?? ''));
    $eventUtr = trim((string)($payload['utr'] ?? $payload['transaction_id'] ?? ''));
    if ($utr === '' || ($eventUtr !== '' && $eventUtr !== $utr)) {
        fg_reply(409, ['status'=>'error','message'=>'Missing or inconsistent transaction reference']);
    }

    $credit = fg_credit_wallet($config, (string)$intent['uid'], $orderId, $expectedAmount, $utr, (string)($verified['transaction_id'] ?? $payload['transaction_id'] ?? ''));
    fg_reply(200, ['status'=>'success','message'=>($credit['already_credited'] ? 'Order already credited' : 'Wallet credited successfully'),'order_id'=>$orderId]);
} catch (Throwable $e) {
    error_log('[FamGateway webhook] ' . $e->getMessage());
    fg_reply(500, ['status'=>'error','message'=>'Webhook processing failed; gateway may retry']);
}

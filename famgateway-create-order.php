<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$config = require __DIR__ . '/famgateway.config.php';

function reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function readJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

function firebaseRequest(array $config, string $path, string $method = 'GET', ?array $body = null): array {
    $base = rtrim((string)$config['firebase_database_url'], '/');
    $token = (string)$config['firebase_admin_token'];
    if ($token === '') {
        throw new RuntimeException('Firebase Admin credential is not configured');
    }
    $url = $base . '/' . ltrim($path, '/') . '.json?auth=' . rawurlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $code < 200 || $code >= 300) {
        throw new RuntimeException('Firebase request failed (' . $code . ')' . ($error ? ': ' . $error : ''));
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['value' => $decoded];
}

function gatewayRequest(array $config, string $path, string $method = 'GET', ?array $body = null): array {
    if ($config['api_key'] === '') {
        throw new RuntimeException('FAMGATEWAY_API_KEY is not configured');
    }
    $url = 'https://famgateway.in' . $path;
    $headers = ['Accept: application/json', 'X-Api-Key: ' . $config['api_key']];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('Gateway network error: ' . $error);
    $decoded = json_decode($response, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded)) {
        throw new RuntimeException('FamGateway returned an invalid/error response (HTTP ' . $code . ')');
    }
    return $decoded;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['status'=>'error','message'=>'POST required']);
    if (empty($_SERVER['HTTP_AUTHORIZATION'])) reply(401, ['status'=>'error','message'=>'Firebase ID token required']);
    $rawAuth = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    if (!preg_match('/^Bearer\s+(.+)$/i', $rawAuth, $matches)) reply(401, ['status'=>'error','message'=>'Invalid authorization header']);
    $idToken = $matches[1];

    // Verify Firebase ID token through Firebase Identity Toolkit REST API.
    $firebaseApiKey = getenv('FIREBASE_WEB_API_KEY') ?: '';
    if ($firebaseApiKey === '') reply(500, ['status'=>'error','message'=>'FIREBASE_WEB_API_KEY is not configured']);
    $verifyCh = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode($firebaseApiKey));
    curl_setopt_array($verifyCh, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['idToken'=>$idToken]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $verifyResponse = curl_exec($verifyCh);
    $verifyCode = (int)curl_getinfo($verifyCh, CURLINFO_HTTP_CODE);
    curl_close($verifyCh);
    $identity = json_decode((string)$verifyResponse, true);
    $user = $identity['users'][0] ?? null;
    if ($verifyCode !== 200 || !is_array($user) || empty($user['localId'])) {
        reply(401, ['status'=>'error','message'=>'Firebase session could not be verified']);
    }

    $input = readJson();
    $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($amount === false || !is_finite((float)$amount) || $amount < (float)$config['min_amount'] || $amount > (float)$config['max_amount']) {
        reply(422, ['status'=>'error','message'=>'Amount must be between ₹10 and ₹50,000']);
    }
    $amount = round((float)$amount, 2);
    if (abs($amount * 100 - round($amount * 100)) > 0.00001) reply(422, ['status'=>'error','message'=>'Amount may have at most two decimal places']);

    $uid = (string)$user['localId'];
    $userRecord = firebaseRequest($config, 'users/' . rawurlencode($uid));
    if (!is_array($userRecord)) reply(404, ['status'=>'error','message'=>'Panel account not found']);

    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
        reply(500, ['status'=>'error','message'=>'PANEL_BASE_URL must be configured as the canonical HTTPS domain']);
    }
    $order = gatewayRequest($config, '/api/create-order', 'POST', [
        'amount' => number_format($amount, 2, '.', ''),
        'customer_name' => (string)($userRecord['username'] ?? $user['displayName'] ?? 'SMM Panel User'),
        'customer_email' => (string)($user['email'] ?? ''),
        'webhook_url' => $baseUrl . '/famgateway-webhook.php',
    ]);
    $data = $order['data'] ?? null;
    if (($order['status'] ?? '') !== 'success' || !is_array($data) || empty($data['order_id']) || empty($data['checkout_url'])) {
        reply(502, ['status'=>'error','message'=>'FamGateway did not return a valid order']);
    }

    $orderId = (string)$data['order_id'];
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $orderId)) reply(502, ['status'=>'error','message'=>'FamGateway returned an invalid order ID']);
    // Record the intended user and amount server-side before returning checkout URL.
    $record = [
        'uid' => $uid,
        'amount' => number_format($amount, 2, '.', ''),
        'status' => 'pending',
        'gateway' => 'FamGateway',
        'created_at' => gmdate('c'),
    ];
    firebaseRequest($config, 'famgateway_orders/' . $orderId, 'PUT', $record);

    reply(200, [
        'status'=>'success',
        'order_id'=>$orderId,
        'amount'=>number_format($amount, 2, '.', ''),
        'checkout_url'=>(string)$data['checkout_url'],
        'qr_url'=>(string)($data['qr_url'] ?? ''),
        'upi_intent'=>(string)($data['upi_intent'] ?? ''),
        'expires_at_ist'=>$data['expires_at_ist'] ?? null
    ]);
} catch (Throwable $e) {
    error_log('[FamGateway create order] ' . $e->getMessage());
    reply(500, ['status'=>'error','message'=>'Unable to create payment order. Check server configuration/logs.']);
}
